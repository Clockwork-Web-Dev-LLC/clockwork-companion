<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Backup\SqlImporter;
use PHPUnit\Framework\TestCase;

class SqlImporterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        SqlImporter::$testImporter = null;
        $this->tempDir = sys_get_temp_dir() . '/cwk_sql_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        SqlImporter::$testImporter = null;
        \ClockworkCompanion\Backup\Paths::rmrf($this->tempDir);
        parent::tearDown();
    }

    /**
     * @return object mock wpdb capturing executed queries in $executed
     */
    private function installMockWpdb(array &$executed): object
    {
        $mockWpdb = new class($executed) {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public $executed;
            public function __construct(&$executed) { $this->executed = &$executed; }
            public function query(string $sql): bool {
                $this->executed[] = $sql;
                return true;
            }
        };
        $GLOBALS['wpdb'] = $mockWpdb;

        return $mockWpdb;
    }

    public function testNonWhitelistedStatementsAreSkippedNotExecuted(): void
    {
        $sqlPath = $this->tempDir . '/dump.sql';
        file_put_contents(
            $sqlPath,
            "-- Foreign-flavored dump\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "LOCK TABLES `wp_posts` WRITE;\n"
            . "CREATE TABLE `wp_posts` (`id` int);\n"
            . "INSERT INTO `wp_posts` VALUES (1);\n"
            . "ALTER TABLE `foreign_meta` ADD COLUMN `x` int;\n"
            . "TRUNCATE TABLE `foreign_meta`;\n"
            . "UPDATE `foreign_meta` SET `x` = 1;\n"
            . "UNLOCK TABLES;\n"
            . "DROP TABLE IF EXISTS `foreign_meta`;\n"
            . "CREATE TABLE `foreign_meta` (`id` int);\n"
            . "INSERT INTO `foreign_meta` VALUES (2);\n"
            . "SET FOREIGN_KEY_CHECKS=1;\n"
        );

        $executed = [];
        $this->installMockWpdb($executed);

        $importer = new SqlImporter();
        $result = $importer->import($sqlPath);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['matched_tables']);
        // foreign_meta DROP/CREATE/INSERT are counted (deduped) in skipped_tables.
        $this->assertSame(1, $result['skipped_tables']);
        // LOCK TABLES, ALTER, TRUNCATE, UPDATE, UNLOCK TABLES => 5 skipped statements.
        $this->assertSame(5, $result['skipped_statements']);

        $joined = implode("\n", $executed);
        $this->assertStringContainsString('CREATE TABLE `wp_posts`', $joined);
        $this->assertStringContainsString('INSERT INTO `wp_posts`', $joined);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS', $joined);
        $this->assertStringNotContainsString('LOCK TABLES', $joined);
        $this->assertStringNotContainsString('ALTER TABLE', $joined);
        $this->assertStringNotContainsString('TRUNCATE', $joined);
        $this->assertStringNotContainsString('UPDATE', $joined);
        $this->assertStringNotContainsString('foreign_meta', $joined);
    }

    public function testSingleLineLongerThanReadChunkImportsAsOneStatement(): void
    {
        // A single INSERT line well beyond the 64KB gzgets/fgets chunk size must not
        // be split into bogus statements at chunk boundaries.
        $bigValue = str_repeat('a', 70000) . "\\;" . str_repeat('b', 70000);
        $insert = "INSERT INTO `wp_posts` VALUES (1, '{$bigValue}');";

        $sqlPath = $this->tempDir . '/long.sql';
        file_put_contents(
            $sqlPath,
            "CREATE TABLE `wp_posts` (`id` int, `content` longtext);\n"
            . $insert . "\n"
        );

        $executed = [];
        $this->installMockWpdb($executed);

        $importer = new SqlImporter();
        $result = $importer->import($sqlPath);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['skipped_statements']);
        $this->assertCount(2, $executed, 'Expected exactly CREATE + one whole INSERT statement');
        $this->assertSame($insert, trim($executed[1]));
    }

    public function testGzImportWorksWhenZlibAvailable(): void
    {
        if (! function_exists('gzopen')) {
            $this->markTestSkipped('zlib not available');
        }

        $sqlPath = $this->tempDir . '/dump.sql.gz';
        $gz = gzopen($sqlPath, 'wb');
        gzwrite($gz, "CREATE TABLE `wp_options` (`id` int);\nLOCK TABLES `wp_options` WRITE;\n");
        gzclose($gz);

        $executed = [];
        $this->installMockWpdb($executed);

        $importer = new SqlImporter();
        $result = $importer->import($sqlPath);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['matched_tables']);
        $this->assertSame(1, $result['skipped_statements']);
    }
}
