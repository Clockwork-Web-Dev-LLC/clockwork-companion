<?php

namespace ClockworkCompanion\Backup;

/**
 * Streams and executes statements from a .sql or .sql.gz dump file.
 * Scope: current-prefix tables only ($wpdb->prefix), passing through SET statements.
 * Whitelist execution: only SET plus DROP TABLE / CREATE TABLE / INSERT INTO on
 * current-prefix tables ever run; everything else is skipped and counted.
 * Fails closed on prefix mismatch (if zero tables match $wpdb->prefix).
 *
 * The dump is read twice. The first pass runs nothing and rejects a dump
 * that is truncated or matches no table; only then does the second pass
 * execute it. Tables are replaced in place, one at a time: a statement the
 * database rejects during the second pass still leaves the site partly
 * restored, so the caller keeps maintenance mode on when import() fails.
 *
 * Dumps written by DatabaseDumper before format 2 have every `%` in their
 * string values replaced by a wpdb placeholder token. Those dumps are
 * recognised by their missing format marker and repaired while importing
 * (see restorePercentSigns()), so archives taken by older plugin versions
 * still restore correctly.
 */
class SqlImporter
{
    /** @var callable|null Test seam: fn(string $sqlFilePath, string $tablePrefix): array{ok: bool, error?: string, detail?: string, matched_tables: int, skipped_tables: int, skipped_statements: int} */
    public static $testImporter = null;

    /**
     * Stream and execute SQL statements from a .sql or .sql.gz dump file.
     *
     * @param  string  $sqlFilePath Path to the dump file.
     * @param  string|null  $prefix Table prefix (defaults to $wpdb->prefix).
     * @return array{ok: bool, error?: string, detail?: string, matched_tables: int, skipped_tables: int, skipped_statements: int}
     */
    public function import(string $sqlFilePath, ?string $prefix = null): array
    {
        global $wpdb;

        if (! file_exists($sqlFilePath)) {
            return [
                'ok' => false,
                'error' => 'sql_failed',
                'detail' => "SQL dump file does not exist: {$sqlFilePath}",
                'matched_tables' => 0,
                'skipped_tables' => 0,
                'skipped_statements' => 0,
            ];
        }

        $prefix = $prefix !== null ? $prefix : (isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_');

        if (is_callable(self::$testImporter)) {
            $res = call_user_func(self::$testImporter, $sqlFilePath, $prefix);
            if (is_array($res)) {
                return $res;
            }

            return [
                'ok' => (bool) $res,
                'matched_tables' => 1,
                'skipped_tables' => 0,
                'skipped_statements' => 0,
            ];
        }

        $isGz = str_ends_with(strtolower($sqlFilePath), '.gz');
        if ($isGz && ! function_exists('gzopen')) {
            return [
                'ok' => false,
                'error' => 'zlib_missing',
                'detail' => 'Dump file is gzip-compressed but the PHP zlib extension (gzopen) is not available.',
                'matched_tables' => 0,
                'skipped_tables' => 0,
                'skipped_statements' => 0,
            ];
        }

        // First pass: read the whole dump without running anything. The import
        // drops each table before refilling it, so a dump that stops half-way
        // (full disk while it was written, truncated download) must be
        // rejected here, while the live tables are still untouched.
        $scan = $this->walk($sqlFilePath, $isGz, $prefix, null);
        if (! $scan['ok']) {
            return $scan;
        }

        if (! $scan['complete']) {
            return [
                'ok' => false,
                'error' => 'dump_incomplete',
                'detail' => 'The database dump is truncated: it does not end with the "'.DatabaseDumper::FOOTER.'" marker. Nothing was imported.',
                'matched_tables' => $scan['matched_tables'],
                'skipped_tables' => $scan['skipped_tables'],
                'skipped_statements' => $scan['skipped_statements'],
            ];
        }

        if ($scan['matched_tables'] === 0) {
            return [
                'ok' => false,
                'error' => 'prefix_mismatch',
                'detail' => 'None of the tables in the backup archive matched the current WordPress prefix ('.$prefix.').',
                'matched_tables' => 0,
                'skipped_tables' => $scan['skipped_tables'],
                'skipped_statements' => $scan['skipped_statements'],
            ];
        }

        // Second pass: execute.
        $result = $this->walk($sqlFilePath, $isGz, $prefix, isset($wpdb) && is_object($wpdb) ? $wpdb : null, true);
        unset($result['complete']);

        return $result;
    }

    /**
     * Read the dump statement by statement. With $execute false nothing is
     * run: the pass only classifies statements and reports whether the dump
     * is complete. With $execute true each whitelisted statement is sent to
     * $wpdb and the pass stops at the first failure.
     *
     * @return array{ok: bool, error?: string, detail?: string, matched_tables: int, skipped_tables: int, skipped_statements: int, complete: bool}
     */
    private function walk(string $sqlFilePath, bool $isGz, string $prefix, ?object $wpdb, bool $execute = false): array
    {
        $handle = $isGz ? @gzopen($sqlFilePath, 'rb') : @fopen($sqlFilePath, 'rb');

        if (! $handle) {
            return [
                'ok' => false,
                'error' => 'sql_failed',
                'detail' => "Failed to open SQL file: {$sqlFilePath}",
                'matched_tables' => 0,
                'skipped_tables' => 0,
                'skipped_statements' => 0,
                'complete' => false,
            ];
        }

        $matchedTables = [];
        $skippedTables = [];
        $skippedStatements = 0;
        $buffer = '';
        $format = 1;
        $ownDump = false;
        $sawFooter = false;

        foreach ($this->readLines($handle, $isGz) as $line) {
            $trimmedLine = trim($line);
            if ($buffer === '' && ($trimmedLine === '' || str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '/*'))) {
                if (str_starts_with($trimmedLine, DatabaseDumper::FORMAT_MARKER)) {
                    $format = (int) trim(substr($trimmedLine, strlen(DatabaseDumper::FORMAT_MARKER)));
                }
                if (preg_match(DatabaseDumper::HEADER_PATTERN, $trimmedLine)) {
                    $ownDump = true;
                }
                if (str_starts_with($trimmedLine, DatabaseDumper::FOOTER)) {
                    $sawFooter = true;
                }

                continue;
            }

            // The footer only counts as the end of the dump when no statement follows it.
            $sawFooter = false;
            $buffer .= $line;

            if (str_ends_with(rtrim($line), ';')) {
                $statement = trim($buffer);
                $buffer = '';

                if ($statement === '') {
                    continue;
                }

                if (preg_match('/^SET\s+/i', $statement)) {
                    if ($execute && $wpdb !== null) {
                        $wpdb->query($statement);
                    }
                    continue;
                }

                if (preg_match('/^(?:DROP TABLE(?:\s+IF EXISTS)?|CREATE TABLE(?:\s+IF NOT EXISTS)?|INSERT INTO)\s+[`"]?([^`"\s]+)[`"]?/i', $statement, $matches)) {
                    $table = $matches[1];
                    if (str_starts_with($table, $prefix)) {
                        $matchedTables[$table] = true;
                        if (! $execute || $wpdb === null) {
                            continue;
                        }
                        if (stripos($statement, 'INSERT') === 0) {
                            if ($format < 2) {
                                $statement = self::restorePercentSigns($statement);
                            }
                            // Only DatabaseDumper's own older dumps can repeat a
                            // row; a dump from another tool is replayed as written.
                            if ($ownDump && $format < 3) {
                                $statement = self::tolerateRepeatedRows($statement);
                            }
                        }
                        $res = $wpdb->query($statement);
                        if ($res === false) {
                            $this->closeHandle($handle, $isGz);

                            return [
                                'ok' => false,
                                'error' => 'sql_failed',
                                'detail' => $wpdb->last_error ?: "Failed executing statement on table {$table}",
                                'matched_tables' => count($matchedTables),
                                'skipped_tables' => count($skippedTables),
                                'skipped_statements' => $skippedStatements,
                                'complete' => false,
                            ];
                        }
                    } else {
                        $skippedTables[$table] = true;
                    }
                } else {
                    // Whitelist execution: our own DatabaseDumper only ever emits
                    // SET/DROP TABLE/CREATE TABLE/INSERT INTO, so skipping every other
                    // statement (LOCK TABLES, ALTER, TRUNCATE, UPDATE, ...) is
                    // safe-by-construction and keeps foreign dumps from touching the live DB.
                    $skippedStatements++;
                }
            }
        }

        $this->closeHandle($handle, $isGz);

        return [
            'ok' => true,
            'matched_tables' => count($matchedTables),
            'skipped_tables' => count($skippedTables),
            'skipped_statements' => $skippedStatements,
            // A statement still in the buffer was cut off mid-way.
            'complete' => $sawFooter && $buffer === '',
        ];
    }

    /**
     * Dumps written before format 3 paged rows by offset while the site kept
     * writing, so a row inserted mid-dump could push another onto two pages
     * and be written twice. The second copy would stop the import on a
     * duplicate-key error with half the tables already replaced; IGNORE
     * skips it instead. Both copies are the same row.
     */
    public static function tolerateRepeatedRows(string $statement): string
    {
        return (string) preg_replace('/^INSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $statement, 1);
    }

    /**
     * Undo the placeholder escaping baked into dumps written before format 2.
     *
     * In those dumps every `%` in a string value is a wpdb placeholder token:
     * `{`, 64 lowercase hex characters, `}`. Because the substitution covered
     * every `%`, such a dump contains no literal `%` in its values at all, and
     * a brace-wrapped SHA-256 does not otherwise occur in WordPress data — so
     * turning each token back into `%` restores the original rows, including
     * the byte lengths recorded inside serialized values.
     */
    public static function restorePercentSigns(string $statement): string
    {
        if (! str_contains($statement, '{')) {
            return $statement;
        }

        return (string) preg_replace('/\{[a-f0-9]{64}\}/', '%', $statement);
    }

    /**
     * Yield full physical lines from the dump. gzgets/fgets with a length cap can
     * return a partial line (a single INSERT tuple can exceed 64KB), so chunks are
     * accumulated until the buffer ends with a newline (or EOF) before being treated
     * as one line for statement-boundary detection.
     *
     * @param  resource  $handle
     * @return \Generator<string>
     */
    private function readLines($handle, bool $isGz): \Generator
    {
        $pending = '';

        while (! ($isGz ? gzeof($handle) : feof($handle))) {
            $chunk = $isGz ? gzgets($handle, 65536) : fgets($handle, 65536);
            if ($chunk === false) {
                break;
            }

            $pending .= $chunk;

            if (str_ends_with($pending, "\n")) {
                yield $pending;
                $pending = '';
            }
        }

        if ($pending !== '') {
            yield $pending;
        }
    }

    /**
     * @param  resource  $handle
     */
    private function closeHandle($handle, bool $isGz): void
    {
        if ($isGz) {
            gzclose($handle);
        } else {
            fclose($handle);
        }
    }
}
