<?php

namespace ClockworkCompanion\Backup;

/**
 * Pure PHP WordPress Database Dumper.
 *
 * Exports all tables and data into a .sql or .sql.gz stream using $wpdb.
 * Safe across all hosting environments (including WP Engine, Pressable, Kinsta)
 * where shell commands / mysqldump binaries may be unavailable or restricted.
 *
 * Consistency: the dump runs inside one REPEATABLE READ transaction opened
 * WITH CONSISTENT SNAPSHOT, so every InnoDB table is read as of the same
 * instant even while the site keeps writing. Tables on a non-transactional
 * engine (MyISAM, MEMORY) are not covered by the snapshot and are read as they
 * are when their turn comes; the result reports them under `warnings`.
 *
 * Paging: rows are read in batches that continue after the last key value of
 * the previous batch (primary key, or failing that a unique key without
 * nullable columns). Unlike `LIMIT offset, n`, that neither skips nor repeats
 * rows when other rows are inserted or deleted meanwhile, and its cost does
 * not grow with the offset. A table with no such key falls back to offset
 * paging, ordered by every column while it is small enough to sort per batch
 * (UNKEYED_ORDERED_MAX_ROWS); above that it is paged unordered, which is only
 * reliable when the snapshot covers the table — also reported under `warnings`.
 *
 * Views are not dumped: their rows belong to the underlying tables, and
 * restoring them as tables would replace the view with a stale copy.
 */
class DatabaseDumper
{
    /**
     * Header line identifying the dump format.
     *
     * Format 1 (no such line): every `%` in a string value was replaced by
     * wpdb's per-request placeholder token (see escapeValue()), and rows were
     * paged by offset without a snapshot, so a row could appear twice.
     * Format 2: `%` written literally.
     * Format 3: rows read from one snapshot and paged by key.
     *
     * SqlImporter uses the absence of this marker, or its value, to repair
     * what older dumps got wrong.
     */
    public const FORMAT_MARKER = '-- Clockwork-Dump-Format:';

    public const FORMAT = 3;

    /** Matches the first header line of a dump written by this class (either plugin). */
    public const HEADER_PATTERN = '/^-- Clockwork \w+ Database Backup\b/';

    /** Last line of a complete dump. SqlImporter refuses a dump without it. */
    public const FOOTER = '-- Dump completed';

    public const BATCH_SIZE = 200;

    /**
     * Largest table without a usable key that is still sorted by every column
     * for each batch. Sorting costs a full scan per batch, so above this the
     * dump would not finish in a request.
     */
    public const UNKEYED_ORDERED_MAX_ROWS = 50000;

    /**
     * Option rows that are credentials or bookkeeping for requests in flight:
     * single-use login nonces, consumed-signature markers and the plugin's own
     * transients (operation locks, rate counters, pending 2FA logins). They
     * are worthless in a restore and have no business in an archive.
     * `\_` keeps the underscore literal inside LIKE.
     */
    public const EPHEMERAL_OPTION_PATTERNS = [
        'clockwork\\_sso\\_%',
        'clockwork\\_hmac\\_used\\_%',
        '\\_transient\\_clockwork\\_%',
        '\\_transient\\_timeout\\_clockwork\\_%',
    ];

    /**
     * Total raw SQL bytes written across all table headers and INSERT statements.
     */
    private int $rawBytes = 0;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Dump all database tables to the specified file path.
     * If the path ends in .gz and zlib is supported, it streams gzipped SQL directly.
     *
     * A dump that could not be written completely (query error, short write,
     * full disk) is deleted and reported as a failure — never returned as ok.
     *
     * @return array{
     *     ok: bool,
     *     tables_count: int,
     *     total_rows: int,
     *     raw_bytes: int,
     *     size_bytes: int,
     *     tables: array<int, array{name: string, rows: int, size_bytes: int}>,
     *     consistent_snapshot?: bool,
     *     skipped_views?: int,
     *     warnings?: list<string>,
     *     error?: string
     * }
     */
    public function dump(string $outputPath): array
    {
        $this->rawBytes = 0;
        $this->warnings = [];

        if (! defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        if (! defined('ARRAY_N')) {
            define('ARRAY_N', 'ARRAY_N');
        }

        global $wpdb;

        if (! isset($wpdb) || ! is_object($wpdb)) {
            return $this->failure('WordPress $wpdb is unavailable.');
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return $this->failure("Cannot create backup directory: {$dir}");
        }

        $useGz = str_ends_with($outputPath, '.gz') && function_exists('gzopen');
        $handle = $useGz ? @gzopen($outputPath, 'wb9') : @fopen($outputPath, 'wb');

        if (! $handle) {
            return $this->failure("Failed to open output file for writing: {$outputPath}");
        }

        $snapshot = $this->beginSnapshot($wpdb);
        if (! $snapshot) {
            $this->warnings[] = 'The database did not open a consistent snapshot; tables were read one after another while the site kept writing.';
        }
        $error = null;
        $summary = ['tables_count' => 0, 'total_rows' => 0, 'tables' => [], 'skipped_views' => 0];

        try {
            $summary = $this->writeDump($wpdb, $handle, $useGz, $snapshot);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        } finally {
            if ($snapshot) {
                $wpdb->query('COMMIT');
            }
            if ($useGz) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
        }

        // Closing never reports a failed flush, and gzip buffers its output,
        // so a full disk can pass every write call above. Read the file back.
        if ($error === null) {
            $error = $this->verifyWritten($outputPath, $useGz);
        }

        if ($error !== null) {
            @unlink($outputPath);

            return $this->failure($error);
        }

        return [
            'ok' => true,
            'tables_count' => $summary['tables_count'],
            'total_rows' => $summary['total_rows'],
            'raw_bytes' => $this->rawBytes,
            'size_bytes' => (int) @filesize($outputPath),
            'tables' => $summary['tables'],
            'consistent_snapshot' => $snapshot,
            'skipped_views' => $summary['skipped_views'],
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  resource  $handle
     * @return array{tables_count: int, total_rows: int, tables: array<int, array{name: string, rows: int, size_bytes: int}>, skipped_views: int}
     */
    private function writeDump(object $wpdb, $handle, bool $useGz, bool $snapshot): array
    {
        $header = "-- Clockwork Companion Database Backup\n"
            ."-- Generated: ".gmdate('Y-m-d H:i:s')." UTC\n"
            ."-- Database: ".(defined('DB_NAME') ? constant('DB_NAME') : 'wordpress')."\n"
            .self::FORMAT_MARKER.' '.self::FORMAT."\n\n"
            ."SET FOREIGN_KEY_CHECKS=0;\n"
            ."SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n"
            ."SET time_zone = \"+00:00\";\n\n";

        $this->write($handle, $header, $useGz);

        $statusRows = (array) $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        $statusMap = [];
        foreach ($statusRows as $status) {
            if (is_array($status) && isset($status['Name']) && is_string($status['Name'])) {
                $statusMap[$status['Name']] = [
                    'data_length' => isset($status['Data_length']) ? (int) $status['Data_length'] : 0,
                    'index_length' => isset($status['Index_length']) ? (int) $status['Index_length'] : 0,
                    'engine' => isset($status['Engine']) ? (string) $status['Engine'] : '',
                    // A view has no engine and the fixed comment "VIEW".
                    'is_view' => ! isset($status['Engine']) && strtoupper((string) ($status['Comment'] ?? '')) === 'VIEW',
                ];
            }
        }

        $tables = (array) $wpdb->get_col('SHOW TABLES');
        $tablesCount = 0;
        $totalRowsSum = 0;
        $tablesMeta = [];
        $skippedViews = 0;

        foreach ($tables as $table) {
            $table = (string) $table;
            if ($table === '') {
                continue;
            }

            if (! empty($statusMap[$table]['is_view'])) {
                $skippedViews++;

                continue;
            }

            $createRow = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            $createSql = $createRow[1] ?? null;

            if (! $createSql) {
                throw new \RuntimeException("Could not read the structure of table {$table}: ".$this->lastError($wpdb));
            }

            $this->write(
                $handle,
                "\n-- Table structure for `{$table}`\n"
                    ."DROP TABLE IF EXISTS `{$table}`;\n"
                    .$createSql.";\n\n",
                $useGz
            );

            $engine = (string) ($statusMap[$table]['engine'] ?? '');
            if ($snapshot && $engine !== '' && strcasecmp($engine, 'InnoDB') !== 0) {
                $this->warnings[] = "Table {$table} uses the {$engine} engine and was read outside the consistent snapshot.";
            }

            $rowsWritten = $this->dumpRows($wpdb, $handle, $useGz, $table);

            $tablesCount++;
            $totalRowsSum += $rowsWritten;
            $tablesMeta[] = [
                'name' => $table,
                'rows' => $rowsWritten,
                'size_bytes' => (int) (($statusMap[$table]['data_length'] ?? 0) + ($statusMap[$table]['index_length'] ?? 0)),
            ];
        }

        $this->write($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n".self::FOOTER."\n", $useGz);

        return [
            'tables_count' => $tablesCount,
            'total_rows' => $totalRowsSum,
            'tables' => $tablesMeta,
            'skipped_views' => $skippedViews,
        ];
    }

    /**
     * Write every row of one table as batched INSERT statements.
     *
     * @param  resource  $handle
     * @return int Rows written.
     */
    private function dumpRows(object $wpdb, $handle, bool $useGz, string $table): int
    {
        $columns = $this->columnTypes($wpdb, $table);
        $key = $this->pagingKey($wpdb, $table, $columns);
        $filter = $this->rowFilter($columns);

        $orderBy = '';
        if ($key === [] && $columns !== []) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            if ($count <= self::UNKEYED_ORDERED_MAX_ROWS) {
                $orderBy = ' ORDER BY '.implode(', ', array_map(fn (string $c): string => "`{$c}`", array_keys($columns)));
            } else {
                $this->warnings[] = "Table {$table} has no primary or unique key and {$count} rows; it was paged without a stable order.";
            }
        }

        $written = 0;
        $offset = 0;
        $after = null;
        $headerWritten = false;

        while (true) {
            if ($key !== []) {
                $conditions = array_filter([$filter, $after === null ? '' : $this->afterKeyCondition($wpdb, $key, $columns, $after)]);
                $query = "SELECT * FROM `{$table}`"
                    .($conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions))
                    .' ORDER BY '.implode(', ', array_map(fn (string $c): string => "`{$c}`", $key))
                    .' LIMIT '.self::BATCH_SIZE;
            } else {
                $query = "SELECT * FROM `{$table}`"
                    .($filter === '' ? '' : ' WHERE '.$filter)
                    .$orderBy
                    .' LIMIT '.$offset.', '.self::BATCH_SIZE;
            }

            $rows = $wpdb->get_results($query, ARRAY_A);
            $queryError = $this->lastError($wpdb);
            if (method_exists($wpdb, 'flush')) {
                $wpdb->flush();
            }

            // An empty batch is the normal end of a table; a failed query looks
            // the same from here unless the error is checked.
            if ($queryError !== '') {
                throw new \RuntimeException("Reading table {$table} failed: {$queryError}");
            }
            if (empty($rows)) {
                break;
            }

            if (! $headerWritten) {
                $this->write($handle, "-- Dumping data for `{$table}`\n", $useGz);
                $headerWritten = true;
            }

            $inserts = [];
            foreach ($rows as $row) {
                $fields = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $fields[] = 'NULL';
                    } elseif (is_int($val) || is_float($val)) {
                        $fields[] = $val;
                    } else {
                        $fields[] = "'".$this->escapeValue($wpdb, (string) $val)."'";
                    }
                }
                $inserts[] = '('.implode(', ', $fields).')';
            }

            $this->write($handle, "INSERT INTO `{$table}` VALUES \n".implode(",\n", $inserts).";\n", $useGz);
            $written += count($rows);

            if (count($rows) < self::BATCH_SIZE) {
                break;
            }

            if ($key !== []) {
                $last = (array) end($rows);
                $next = [];
                foreach ($key as $column) {
                    $next[$column] = $last[$column] ?? null;
                }
                // The next batch must start strictly after this one. If the key
                // did not move, a value did not survive the round trip through
                // PHP and the loop would never end.
                if ($next === $after || in_array(null, $next, true)) {
                    throw new \RuntimeException("Cannot page table {$table} by its key.");
                }
                $after = $next;
            } else {
                $offset += self::BATCH_SIZE;
            }
        }

        return $written;
    }

    /**
     * Open the transaction every table is read in. Best effort: a database
     * that refuses (or a drop-in that is not MySQL) still gets a dump, with
     * `consistent_snapshot` false in the result.
     */
    private function beginSnapshot(object $wpdb): bool
    {
        if (! method_exists($wpdb, 'query')) {
            return false;
        }

        $restoreErrors = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : null;

        // WITH CONSISTENT SNAPSHOT is silently ignored below REPEATABLE READ.
        $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $started = $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') !== false;

        if ($restoreErrors !== null) {
            $wpdb->suppress_errors((bool) $restoreErrors);
        }

        return $started;
    }

    /**
     * Column name => lower-cased type, in table order.
     *
     * @return array<string, string>
     */
    private function columnTypes(object $wpdb, string $table): array
    {
        $columns = [];
        foreach ((array) $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A) as $column) {
            if (is_array($column) && isset($column['Field'])) {
                $columns[(string) $column['Field']] = strtolower((string) ($column['Type'] ?? ''));
            }
        }

        return $columns;
    }

    /**
     * The columns to page by: the primary key, else the first unique key whose
     * columns are all NOT NULL. Empty when the table has neither, or when a
     * key column's type cannot be compared reliably after a round trip through
     * PHP (floating point, BIT, ENUM/SET, which order by their internal index).
     *
     * @param  array<string, string>  $columns
     * @return list<string>
     */
    private function pagingKey(object $wpdb, string $table, array $columns): array
    {
        $indexes = [];
        foreach ((array) $wpdb->get_results("SHOW KEYS FROM `{$table}`", ARRAY_A) as $row) {
            if (! is_array($row) || (int) ($row['Non_unique'] ?? 1) !== 0) {
                continue;
            }
            $name = (string) ($row['Key_name'] ?? '');
            $column = (string) ($row['Column_name'] ?? '');
            // A prefix index (Sub_part) is unique on the prefix only, and an
            // expression index has no column to compare.
            $usable = $column !== ''
                && ($row['Sub_part'] ?? null) === null
                && strtoupper((string) ($row['Null'] ?? '')) !== 'YES'
                && isset($columns[$column])
                && ! preg_match('/^(float|double|real|bit|enum|set|json|geometry|point|linestring|polygon|multi|geomcollection)/', $columns[$column]);

            $indexes[$name]['usable'] = ($indexes[$name]['usable'] ?? true) && $usable;
            $indexes[$name]['columns'][(int) ($row['Seq_in_index'] ?? 0)] = $column;
        }

        uksort($indexes, fn (string $a, string $b): int => ($b === 'PRIMARY') <=> ($a === 'PRIMARY'));

        foreach ($indexes as $index) {
            if ($index['usable']) {
                ksort($index['columns']);

                return array_values($index['columns']);
            }
        }

        return [];
    }

    /**
     * SQL selecting the rows that sort strictly after $after on the key
     * columns: `a > 1 OR (a = 1 AND b > 2)`, nested for longer keys.
     *
     * @param  list<string>  $key
     * @param  array<string, string>  $columns
     * @param  array<string, mixed>  $after
     */
    private function afterKeyCondition(object $wpdb, array $key, array $columns, array $after): string
    {
        $condition = '';
        foreach (array_reverse($key) as $column) {
            $literal = $this->keyLiteral($wpdb, $columns[$column] ?? '', (string) $after[$column]);
            $condition = $condition === ''
                ? "`{$column}` > {$literal}"
                : "`{$column}` > {$literal} OR (`{$column}` = {$literal} AND ({$condition}))";
        }

        return '('.$condition.')';
    }

    /**
     * A key value as a literal of the column's own type, so the comparison
     * uses the index and the column's ordering: bare numbers for numeric
     * columns (a quoted one would be compared as a float and lose precision on
     * large BIGINTs), hex for binary columns, an escaped string otherwise.
     */
    private function keyLiteral(object $wpdb, string $type, string $value): string
    {
        if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|year)/', $type)
            && preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return $value;
        }

        if (preg_match('/^(binary|varbinary|tinyblob|blob|mediumblob|longblob)/', $type)) {
            return $value === '' ? "''" : '0x'.bin2hex($value);
        }

        return "'".$this->escapeValue($wpdb, $value)."'";
    }

    /**
     * WHERE fragment leaving EPHEMERAL_OPTION_PATTERNS out of an options
     * table; empty for every other table.
     *
     * @param  array<string, string>  $columns
     */
    private function rowFilter(array $columns): string
    {
        if (! isset($columns['option_name'], $columns['option_value'])) {
            return '';
        }

        return '('.implode(' AND ', array_map(
            fn (string $pattern): string => "`option_name` NOT LIKE '{$pattern}'",
            self::EPHEMERAL_OPTION_PATTERNS
        )).')';
    }

    private function lastError(object $wpdb): string
    {
        return isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
    }

    /**
     * Confirm the file on disk holds everything that was written: the same
     * number of bytes, ending in the footer. Returns an error message, or null
     * when the dump is complete.
     */
    private function verifyWritten(string $outputPath, bool $useGz): ?string
    {
        $handle = $useGz ? @gzopen($outputPath, 'rb') : @fopen($outputPath, 'rb');
        if (! $handle) {
            return "Database dump could not be read back: {$outputPath}";
        }

        $bytes = 0;
        $tail = '';
        while (! ($useGz ? gzeof($handle) : feof($handle))) {
            $chunk = $useGz ? gzread($handle, 1048576) : fread($handle, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            $tail = substr($tail.$chunk, -64);
        }

        if ($useGz) {
            gzclose($handle);
        } else {
            fclose($handle);
        }

        if ($bytes !== $this->rawBytes || ! str_ends_with($tail, self::FOOTER."\n")) {
            return "Database dump is incomplete on disk ({$bytes} of {$this->rawBytes} bytes). The disk may be full.";
        }

        return null;
    }

    /**
     * @return array{ok: bool, tables_count: int, total_rows: int, raw_bytes: int, size_bytes: int, tables: array<int, array{name: string, rows: int, size_bytes: int}>, error: string}
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'tables_count' => 0,
            'total_rows' => 0,
            'raw_bytes' => 0,
            'size_bytes' => 0,
            'tables' => [],
            'error' => $error,
        ];
    }

    /**
     * Escape a string for use inside a quoted SQL literal in the dump file.
     *
     * esc_sql() does more than escape: wpdb::_real_escape() finishes by
     * swapping every `%` for a random per-request token (`{` + 64 hex + `}`)
     * so a later $wpdb->prepare() cannot mistake it for a placeholder. wpdb
     * only swaps it back inside $wpdb->query(), in the same request. A dump
     * is written to a file instead, so without undoing it here every `%` in
     * the database — permalink structures, URL-encoded content, CSS, and
     * the byte counts of serialized options that contain one — reaches the
     * archive as that token and is restored as that token.
     */
    private function escapeValue(object $wpdb, string $value): string
    {
        $escaped = function_exists('esc_sql') ? (string) esc_sql($value) : addslashes($value);

        if (method_exists($wpdb, 'remove_placeholder_escape')) {
            $escaped = (string) $wpdb->remove_placeholder_escape($escaped);
        }

        return $escaped;
    }

    /**
     * @param  resource  $handle
     *
     * @throws \RuntimeException when fewer bytes reach the file than were given.
     */
    private function write($handle, string $data, bool $useGz): void
    {
        $length = strlen($data);
        $written = $useGz ? gzwrite($handle, $data) : fwrite($handle, $data);

        if ($written === false || $written !== $length) {
            throw new \RuntimeException('Short write to the database dump file. The disk may be full.');
        }

        $this->rawBytes += $length;
    }
}
