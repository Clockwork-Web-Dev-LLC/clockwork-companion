<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Backup\DatabaseDumper;
use ClockworkCompanion\Backup\Paths;
use ClockworkCompanion\Backup\SqlImporter;
use PHPUnit\Framework\TestCase;

class DatabaseDumperTest extends TestCase
{
    private string $tempDir;

    /** @var mixed */
    private $previousWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        SqlImporter::$testImporter = null;
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $this->tempDir = sys_get_temp_dir().'/cwk_dump_test_'.bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        Paths::rmrf($this->tempDir);
        parent::tearDown();
    }

    /**
     * A wpdb double that escapes the way core does: every "%" in an escaped
     * value becomes a per-request `{sha256}` token, reversed only by
     * remove_placeholder_escape() (which core calls inside query()).
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     */
    private function installWpdb(array $tables): object
    {
        $wpdb = new class($tables) {
            public string $prefix = 'wp_';

            public string $last_error = '';

            /** @var list<string> */
            public array $executed = [];

            public string $token;

            public function __construct(private array $tables)
            {
                $this->token = '{'.hash('sha256', uniqid('', true)).'}';
            }

            public function add_placeholder_escape(string $query): string
            {
                return str_replace('%', $this->token, $query);
            }

            public function remove_placeholder_escape(string $query): string
            {
                return str_replace($this->token, '%', $query);
            }

            public function prepare(string $query, mixed ...$args): string
            {
                return vsprintf(str_replace('%d', '%u', $query), $args);
            }

            public function get_results(string $query, $output = null): array
            {
                if (preg_match('/^SELECT \* FROM `([^`]+)` LIMIT (\d+), (\d+)$/', $query, $m)) {
                    return array_slice($this->tables[$m[1]] ?? [], (int) $m[2], (int) $m[3]);
                }

                return [];
            }

            public function get_col(string $query): array
            {
                return array_keys($this->tables);
            }

            public function get_var(string $query): int
            {
                preg_match('/FROM `([^`]+)`/', $query, $m);

                return count($this->tables[$m[1]] ?? []);
            }

            public function get_row(string $query, $output = null): array
            {
                preg_match('/TABLE `([^`]+)`/', $query, $m);

                return [$m[1], "CREATE TABLE `{$m[1]}` (`option_name` varchar(191), `option_value` longtext)"];
            }

            public function query(string $sql): bool
            {
                // Core un-escapes the *current request's* token here.
                $this->executed[] = $this->remove_placeholder_escape($sql);

                return true;
            }
        };

        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }

    private function dump(string $file = 'db.sql'): string
    {
        $path = $this->tempDir.'/'.$file;
        $result = (new DatabaseDumper())->dump($path);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        return $path;
    }

    /**
     * esc_sql() replaces "%" with a placeholder token that only wpdb::query()
     * reverses. Written to a file instead, the token used to end up in the
     * archive, so every restored permalink structure, URL-encoded string and
     * serialized option containing a "%" came back corrupted.
     */
    public function testPercentSignsSurviveTheDump(): void
    {
        $serialized = serialize(['discount' => '25% off', 'path' => '/%category%/%postname%/']);
        $wpdb = $this->installWpdb([
            'wp_options' => [
                ['option_name' => 'permalink_structure', 'option_value' => '/%postname%/'],
                ['option_name' => 'promo', 'option_value' => $serialized],
                ['option_name' => 'quoted', 'option_value' => "it's 100%"],
            ],
        ]);

        $sql = (string) file_get_contents($this->dump());

        $this->assertStringContainsString("'/%postname%/'", $sql);
        $this->assertStringContainsString(addslashes($serialized), $sql);
        $this->assertStringContainsString("'it\\'s 100%'", $sql);
        $this->assertStringNotContainsString($wpdb->token, $sql);
        $this->assertDoesNotMatchRegularExpression('/\{[a-f0-9]{64}\}/', $sql);
    }

    public function testDumpDeclaresItsFormat(): void
    {
        $this->installWpdb(['wp_options' => [['option_name' => 'a', 'option_value' => 'b']]]);

        $sql = (string) file_get_contents($this->dump());

        $this->assertStringContainsString(DatabaseDumper::FORMAT_MARKER.' '.DatabaseDumper::FORMAT."\n", $sql);
    }

    public function testDumpThenImportRoundTripsValuesExactly(): void
    {
        $serialized = serialize(['a' => '50%', 'b' => '%7B%22x%22%3A1%7D']);
        $this->installWpdb([
            'wp_options' => [
                ['option_name' => 'permalink_structure', 'option_value' => '/%year%/%postname%/'],
                ['option_name' => 'blob', 'option_value' => $serialized],
            ],
        ]);
        $path = $this->dump('roundtrip.sql.gz');

        // A restore runs in a later request, where wpdb has a different token.
        $restoring = $this->installWpdb([]);
        $result = (new SqlImporter())->import($path, 'wp_');

        $this->assertTrue($result['ok'], $result['detail'] ?? '');
        $inserts = implode("\n", array_filter($restoring->executed, fn ($q) => str_starts_with($q, 'INSERT')));
        $this->assertStringContainsString("'/%year%/%postname%/'", $inserts);
        $this->assertStringContainsString(addslashes($serialized), $inserts);
        $this->assertDoesNotMatchRegularExpression('/\{[a-f0-9]{64}\}/', $inserts);
    }

    /**
     * Archives taken by plugin versions before the fix still hold the tokens.
     * They carry no format marker, and the importer puts the "%" back.
     */
    public function testLegacyDumpWithPlaceholderTokensIsRepairedOnImport(): void
    {
        $token = '{'.hash('sha256', 'a-previous-request').'}';
        $legacyValue = str_replace('%', $token, 's:4:"path";s:12:"/%postname%/";');
        $path = $this->tempDir.'/legacy.sql';
        file_put_contents(
            $path,
            "-- Clockwork Companion Database Backup\n"
            ."-- Generated: 2026-10-01 00:00:00 UTC\n\n"
            ."SET FOREIGN_KEY_CHECKS=0;\n"
            ."DROP TABLE IF EXISTS `wp_options`;\n"
            ."CREATE TABLE `wp_options` (`option_name` varchar(191), `option_value` longtext);\n"
            ."INSERT INTO `wp_options` VALUES \n('permalink_structure', '/{$token}postname{$token}/'),\n('blob', '{$legacyValue}');\n"
            ."-- Dump completed\n"
        );

        $wpdb = $this->installWpdb([]);
        $result = (new SqlImporter())->import($path, 'wp_');

        $this->assertTrue($result['ok'], $result['detail'] ?? '');
        $insert = implode("\n", array_filter($wpdb->executed, fn ($q) => str_starts_with($q, 'INSERT')));
        $this->assertStringContainsString("'/%postname%/'", $insert);
        $this->assertStringContainsString('s:12:"/%postname%/";', $insert);
        $this->assertStringNotContainsString($token, $insert);
    }

    /**
     * A current-format dump is imported verbatim: a brace-wrapped hash that
     * really is in the data must not be rewritten.
     */
    public function testCurrentFormatDumpIsNotRewritten(): void
    {
        $literal = '{'.hash('sha256', 'real content').'}';
        $path = $this->tempDir.'/current.sql';
        file_put_contents(
            $path,
            "-- Clockwork Companion Database Backup\n"
            .DatabaseDumper::FORMAT_MARKER.' '.DatabaseDumper::FORMAT."\n\n"
            ."CREATE TABLE `wp_options` (`option_name` varchar(191), `option_value` longtext);\n"
            ."INSERT INTO `wp_options` VALUES \n('note', '{$literal} is 100% literal');\n"
            ."-- Dump completed\n"
        );

        $wpdb = $this->installWpdb([]);
        $result = (new SqlImporter())->import($path, 'wp_');

        $this->assertTrue($result['ok'], $result['detail'] ?? '');
        $this->assertStringContainsString("'{$literal} is 100% literal'", implode("\n", $wpdb->executed));
    }

    /**
     * A wpdb double with real table metadata: columns, keys, engines, views,
     * and a hook that runs between batches to simulate the site writing
     * while the dump is in progress.
     *
     * @param  array<string, array{columns: array<string, string>, keys?: list<array<string, mixed>>, rows: list<array<string, mixed>>, engine?: string, view?: bool}>  $schema
     */
    private function installSchemaWpdb(array $schema): object
    {
        $wpdb = new class($schema) {
            public string $prefix = 'wp_';

            public string $last_error = '';

            /** @var list<string> */
            public array $statements = [];

            /** @var list<string> */
            public array $selects = [];

            /** @var callable|null */
            public $betweenBatches = null;

            /** Table whose data query fails. */
            public ?string $failOn = null;

            public function __construct(public array $schema) {}

            public function query(string $sql): bool
            {
                $this->statements[] = $sql;

                return true;
            }

            public function suppress_errors(bool $suppress = true): bool
            {
                return false;
            }

            public function get_col(string $query): array
            {
                return array_keys($this->schema);
            }

            public function get_var(string $query): int
            {
                preg_match('/FROM `([^`]+)`/', $query, $m);

                return count($this->schema[$m[1]]['rows'] ?? []);
            }

            public function get_row(string $query, $output = null): array
            {
                preg_match('/TABLE `([^`]+)`/', $query, $m);

                return [$m[1], "CREATE TABLE `{$m[1]}` (`id` int)"];
            }

            public function get_results(string $query, $output = null): array
            {
                $this->last_error = '';
                if ($query === 'SHOW TABLE STATUS') {
                    $status = [];
                    foreach ($this->schema as $name => $t) {
                        $status[] = ! empty($t['view'])
                            ? ['Name' => $name, 'Engine' => null, 'Comment' => 'VIEW']
                            : ['Name' => $name, 'Engine' => $t['engine'] ?? 'InnoDB', 'Comment' => '', 'Data_length' => 0, 'Index_length' => 0];
                    }

                    return array_map(fn ($r) => array_filter($r, fn ($v) => $v !== null), $status);
                }
                if (preg_match('/^SHOW COLUMNS FROM `([^`]+)`/', $query, $m)) {
                    $cols = [];
                    foreach ($this->schema[$m[1]]['columns'] as $field => $type) {
                        $cols[] = ['Field' => $field, 'Type' => $type];
                    }

                    return $cols;
                }
                if (preg_match('/^SHOW KEYS FROM `([^`]+)`/', $query, $m)) {
                    return $this->schema[$m[1]]['keys'] ?? [];
                }
                if (preg_match('/^SELECT \* FROM `([^`]+)`(.*)$/s', $query, $m)) {
                    $this->selects[] = $query;
                    if ($m[1] === $this->failOn) {
                        $this->last_error = 'Lost connection to server during query';

                        return [];
                    }
                    if ($this->betweenBatches !== null && count($this->selects) > 1) {
                        ($this->betweenBatches)($this);
                    }
                    $rows = $this->schema[$m[1]]['rows'];
                    $tail = $m[2];
                    if (preg_match("/`option_name` NOT LIKE/", $tail)) {
                        $rows = array_values(array_filter($rows, fn ($r) => ! preg_match('/^(clockwork_sso_|clockwork_hmac_used_|_transient_clockwork_|_transient_timeout_clockwork_)/', (string) $r['option_name'])));
                    }
                    if (preg_match('/`id` > (\d+)/', $tail, $k)) {
                        $rows = array_values(array_filter($rows, fn ($r) => $r['id'] > (int) $k[1]));
                    }
                    if (preg_match('/LIMIT (\d+), (\d+)$/', $tail, $l)) {
                        return array_slice($rows, (int) $l[1], (int) $l[2]);
                    }
                    preg_match('/LIMIT (\d+)$/', $tail, $l);

                    return array_slice($rows, 0, (int) $l[1]);
                }

                return [];
            }
        };

        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }

    /** @return list<array<string, mixed>> */
    private static function primaryKey(string $column = 'id'): array
    {
        return [['Key_name' => 'PRIMARY', 'Column_name' => $column, 'Non_unique' => 0, 'Seq_in_index' => 1, 'Sub_part' => null, 'Null' => '']];
    }

    /**
     * Offset paging (LIMIT offset, n) skipped a row for every row deleted
     * from an earlier page while the dump ran. Paging after the last key
     * read does not.
     */
    public function testRowsArePagedByPrimaryKeySoConcurrentDeletesDoNotSkipRows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 450; $i++) {
            $rows[] = ['id' => $i, 'title' => "post {$i}"];
        }
        $wpdb = $this->installSchemaWpdb([
            'wp_posts' => ['columns' => ['id' => 'bigint(20) unsigned', 'title' => 'text'], 'keys' => self::primaryKey(), 'rows' => $rows],
        ]);
        // After the first batch, the site deletes post 5 (already dumped).
        $wpdb->betweenBatches = function ($db): void {
            $db->schema['wp_posts']['rows'] = array_values(array_filter($db->schema['wp_posts']['rows'], fn ($r) => $r['id'] !== 5));
            $db->betweenBatches = null;
        };

        $sql = (string) file_get_contents($this->dump());

        $this->assertStringContainsString("(201, 'post 201')", $sql);
        $this->assertSame(450, preg_match_all("/\(\d+, 'post \d+'\)/", $sql));
        $this->assertStringContainsString('ORDER BY `id` LIMIT 200', $wpdb->selects[0]);
        $this->assertStringContainsString('`id` > 200', $wpdb->selects[1]);
    }

    public function testDumpRunsInsideAConsistentSnapshot(): void
    {
        $wpdb = $this->installSchemaWpdb([
            'wp_options' => ['columns' => ['option_id' => 'bigint', 'option_name' => 'varchar(191)', 'option_value' => 'longtext'], 'rows' => []],
        ]);

        $result = (new DatabaseDumper())->dump($this->tempDir.'/snap.sql');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['consistent_snapshot']);
        $this->assertContains('START TRANSACTION WITH CONSISTENT SNAPSHOT', $wpdb->statements);
        $this->assertSame('COMMIT', end($wpdb->statements));
    }

    public function testViewsAreNotDumpedAsTables(): void
    {
        $this->installSchemaWpdb([
            'wp_posts' => ['columns' => ['id' => 'int'], 'keys' => self::primaryKey(), 'rows' => [['id' => 1]]],
            'wp_report_view' => ['columns' => ['id' => 'int'], 'rows' => [['id' => 1]], 'view' => true],
        ]);

        $result = (new DatabaseDumper())->dump($this->tempDir.'/views.sql');
        $sql = (string) file_get_contents($this->tempDir.'/views.sql');

        $this->assertSame(1, $result['skipped_views']);
        $this->assertSame(1, $result['tables_count']);
        $this->assertStringNotContainsString('wp_report_view', $sql);
    }

    public function testShortLivedSecretsAreLeftOutOfTheDump(): void
    {
        $this->installSchemaWpdb([
            'wp_options' => [
                'columns' => ['option_id' => 'bigint(20) unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext'],
                'keys' => self::primaryKey('option_id'),
                'rows' => [
                    ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://example.com'],
                    ['option_id' => 2, 'option_name' => 'clockwork_sso_abc', 'option_value' => 'nonce'],
                    ['option_id' => 3, 'option_name' => 'clockwork_hmac_used_def', 'option_value' => '1'],
                    ['option_id' => 4, 'option_name' => '_transient_clockwork_lock_backup_restore', 'option_value' => '1'],
                ],
            ],
        ]);

        $sql = (string) file_get_contents($this->dump());

        $this->assertStringContainsString("'siteurl'", $sql);
        $this->assertStringNotContainsString('clockwork_sso_abc', $sql);
        $this->assertStringNotContainsString('clockwork_hmac_used_def', $sql);
        $this->assertStringNotContainsString('_transient_clockwork_lock', $sql);
    }

    /**
     * A failed SELECT used to look like the end of the table: the dump
     * carried on and was reported as complete with rows missing.
     */
    public function testAFailedQueryFailsTheDumpAndRemovesTheFile(): void
    {
        $wpdb = $this->installSchemaWpdb([
            'wp_posts' => ['columns' => ['id' => 'int'], 'keys' => self::primaryKey(), 'rows' => [['id' => 1]]],
        ]);
        $wpdb->failOn = 'wp_posts';

        $path = $this->tempDir.'/failed.sql';
        $result = (new DatabaseDumper())->dump($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Lost connection', $result['error']);
        $this->assertFileDoesNotExist($path);
    }

    /**
     * fwrite()/gzwrite() results were ignored, so a full disk produced a
     * truncated dump reported as ok.
     */
    public function testAShortWriteFailsTheDump(): void
    {
        $this->installSchemaWpdb([
            'wp_posts' => ['columns' => ['id' => 'int', 'body' => 'longtext'], 'keys' => self::primaryKey(), 'rows' => [['id' => 1, 'body' => str_repeat('x', 4096)]]],
        ]);
        if (! in_array('cwkfull', stream_get_wrappers(), true)) {
            stream_wrapper_register('cwkfull', DumperFullDiskStream::class);
        }
        DumperFullDiskStream::$capacity = 1024;
        DumperFullDiskStream::$data = '';

        $result = (new DatabaseDumper())->dump('cwkfull://disk/db.sql');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('disk may be full', $result['error']);
    }
}

/**
 * Stream that accepts only $capacity bytes, like a disk that fills up.
 */
class DumperFullDiskStream
{
    public static int $capacity = 0;

    public static string $data = '';

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (str_contains($mode, 'w')) {
            self::$data = '';
        }
        $this->position = 0;

        return true;
    }

    public function stream_write(string $data): int
    {
        $room = max(0, self::$capacity - strlen(self::$data));
        $accepted = substr($data, 0, $room);
        self::$data .= $accepted;

        return strlen($accepted);
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$data);
    }

    public function stream_close(): void {}

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$data), 'mode' => 0100644];
    }

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return str_ends_with($path, '.sql')
            ? ['size' => strlen(self::$data), 'mode' => 0100644]
            : ['size' => 0, 'mode' => 040755];
    }

    public function unlink(string $path): bool
    {
        self::$data = '';

        return true;
    }
}
