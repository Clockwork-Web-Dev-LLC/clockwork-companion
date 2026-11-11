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
        );

        $wpdb = $this->installWpdb([]);
        $result = (new SqlImporter())->import($path, 'wp_');

        $this->assertTrue($result['ok'], $result['detail'] ?? '');
        $this->assertStringContainsString("'{$literal} is 100% literal'", implode("\n", $wpdb->executed));
    }
}
