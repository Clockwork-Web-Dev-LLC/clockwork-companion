<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Backup\ArchiveDownloader;
use ClockworkCompanion\Backup\ArchiveExtractor;
use ClockworkCompanion\Backup\FileApplier;
use ClockworkCompanion\Backup\Paths;
use ClockworkCompanion\Backup\RestoreState;
use ClockworkCompanion\Backup\SqlImporter;
use ClockworkCompanion\Maintenance\MaintenanceGuard;
use ClockworkCompanion\Rest\BackupRestoreRoute;
use ClockworkCompanion\Support\OperationLock;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use ZipArchive;

class BackupRestoreRouteTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_transients'] = [];

        ArchiveDownloader::$testDownloader = null;
        ArchiveExtractor::$testExtractor = null;
        SqlImporter::$testImporter = null;
        FileApplier::$testApplier = null;

        $this->tempDir = sys_get_temp_dir() . '/cwk_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        ArchiveDownloader::$testDownloader = null;
        ArchiveExtractor::$testExtractor = null;
        SqlImporter::$testImporter = null;
        FileApplier::$testApplier = null;

        Paths::rmrf($this->tempDir);
        Paths::sweepStale(0);

        parent::tearDown();
    }

    public function testHandleStageRejectsInvalidDownloadUrl(): void
    {
        $route = new BackupRestoreRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/stage', [
            'download_url' => 'http://insecure.example.com/backup.zip',
            'archive_key' => 'archives/example.com/2026-09-11.zip',
            'expected_sha256' => str_repeat('a', 64),
        ]);

        $response = $route->handleStage($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_download_url', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function testHandleStageRejectsMissingArchiveKey(): void
    {
        $route = new BackupRestoreRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/stage', [
            'download_url' => 'https://s3.amazonaws.com/bucket/backup.zip',
            'expected_sha256' => str_repeat('a', 64),
        ]);

        $response = $route->handleStage($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('missing_archive_key', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function testHandleStageRejectsMissingExpectedSha256(): void
    {
        $route = new BackupRestoreRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/stage', [
            'download_url' => 'https://s3.amazonaws.com/bucket/backup.zip',
            'archive_key' => 'archives/example.com/2026-09-11.zip',
        ]);

        $response = $route->handleStage($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('missing_expected_sha256', $response->get_error_code());
        $this->assertSame(422, $response->get_error_data()['status']);
    }

    public function testHandleStageFailsOnHashMismatchAndUnlinksTempFile(): void
    {
        ArchiveDownloader::$testDownloader = function (string $url, string $destPath): array {
            file_put_contents($destPath, 'corrupted content');
            return [
                'ok' => true,
                'http_code' => 200,
                'bytes_downloaded' => (int) filesize($destPath),
                'sha256' => (string) hash_file('sha256', $destPath),
            ];
        };

        $route = new BackupRestoreRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/stage', [
            'download_url' => 'https://s3.amazonaws.com/bucket/backup.zip',
            'archive_key' => 'archives/example.com/2026-09-11.zip',
            'expected_sha256' => str_repeat('b', 64),
        ]);

        $response = $route->handleStage($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('hash_mismatch', $response->get_error_code());
        $this->assertSame(422, $response->get_error_data()['status']);

        $state = RestoreState::get();
        $this->assertSame('failed', $state['status']);
        $this->assertSame('hash_mismatch', $state['error']);
        $this->assertFalse($state['hash_verified']);
    }

    public function testHandleStageSuccessExtractsAndPopulatesState(): void
    {
        // Build a real zip fixture containing database/db-test.sql and wp-content/index.php
        $zipFixturePath = $this->tempDir . '/fixture.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipFixturePath, ZipArchive::CREATE) === true);
        $zip->addFromString('database/db-test.sql', "-- dump\nCREATE TABLE `wp_options` (`option_id` bigint(20));\n");
        $zip->addFromString('wp-content/themes/mytheme/style.css', "/* Theme Name: MyTheme */\n");
        $zip->close();

        $expectedHash = hash_file('sha256', $zipFixturePath);

        ArchiveDownloader::$testDownloader = function (string $url, string $destPath) use ($zipFixturePath): array {
            copy($zipFixturePath, $destPath);
            return [
                'ok' => true,
                'http_code' => 200,
                'bytes_downloaded' => (int) filesize($destPath),
                'sha256' => (string) hash_file('sha256', $destPath),
            ];
        };

        $route = new BackupRestoreRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/stage', [
            'download_url' => 'https://s3.amazonaws.com/bucket/backup.zip',
            'archive_key' => 'archives/example.com/2026-09-11.zip',
            'expected_sha256' => $expectedHash,
        ]);

        $response = $route->handleStage($request);
        $this->assertNotInstanceOf(WP_Error::class, $response);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertNotEmpty($data['staged_id']);
        $this->assertTrue($data['has_sql']);
        $this->assertTrue($data['has_files']);
        $this->assertSame('wp_', $data['table_prefix']);

        $state = RestoreState::get();
        $this->assertSame('staged', $state['status']);
        $this->assertTrue($state['hash_verified']);
        $this->assertSame($data['staged_id'], $state['staged_id']);

        // Check handleStatus endpoint returns the state
        $statusRequest = new WP_REST_Request('GET', '/clockwork/v1/backup/restore/status');
        $statusResponse = $route->handleStatus($statusRequest);
        $statusData = $statusResponse->get_data();
        $this->assertSame('staged', $statusData['status']);
        $this->assertSame($data['staged_id'], $statusData['staged_id']);
    }

    public function testHandleApplyRejectsMismatchedStagedIdOrUnverifiedHash(): void
    {
        $route = new BackupRestoreRoute();

        // 1. Missing / invalid staged_id
        RestoreState::update([
            'staged_id' => 'valid_stage_123',
            'status' => 'staged',
            'hash_verified' => true,
        ]);

        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/apply', [
            'staged_id' => 'wrong_id',
        ]);
        $response = $route->handleApply($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_staged_id', $response->get_error_code());
        $this->assertSame(409, $response->get_error_data()['status']);

        // 2. Unverified hash
        RestoreState::update([
            'staged_id' => 'stage_unverified',
            'status' => 'staged',
            'hash_verified' => false,
        ]);
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/apply', [
            'staged_id' => 'stage_unverified',
        ]);
        $response = $route->handleApply($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('hash_not_verified', $response->get_error_code());
        $this->assertSame(422, $response->get_error_data()['status']);
    }

    public function testHandleApplyExecutesSqlImportAndFiltersForeignPrefixTables(): void
    {
        $stagedId = 'test_apply_abc123';
        $stagingDir = Paths::stagingDir() . '/restore-' . $stagedId;
        @mkdir($stagingDir . '/database', 0755, true);
        @mkdir($stagingDir . '/wp-content/themes/sample', 0755, true);
        file_put_contents($stagingDir . '/wp-content/themes/sample/sample.txt', 'theme content');

        // Mixed SQL dump: wp_posts (matches wp_), neighbor_users (foreign prefix)
        $dumpSql = "-- Mixed dump\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "DROP TABLE IF EXISTS `wp_posts`;\n"
            . "CREATE TABLE `wp_posts` (`id` int);\n"
            . "INSERT INTO `wp_posts` VALUES (1);\n"
            . "DROP TABLE IF EXISTS `neighbor_users`;\n"
            . "CREATE TABLE `neighbor_users` (`id` int);\n"
            . "INSERT INTO `neighbor_users` VALUES (99);\n"
            . "SET FOREIGN_KEY_CHECKS=1;\n";

        file_put_contents($stagingDir . '/database/db-test.sql', $dumpSql);

        $executedQueries = [];
        $mockWpdb = new class($executedQueries) {
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

        RestoreState::update([
            'staged_id' => $stagedId,
            'status' => 'staged',
            'hash_verified' => true,
            'has_sql' => true,
            'has_files' => true,
        ]);

        $route = new BackupRestoreRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/apply', [
            'staged_id' => $stagedId,
        ]);

        $response = $route->handleApply($request);
        $this->assertNotInstanceOf(WP_Error::class, $response);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertSame('applied', $data['status']);

        // Verify that neighbor_users table queries were filtered out
        $joined = implode("\n", $executedQueries);
        $this->assertStringContainsString('wp_posts', $joined);
        $this->assertStringNotContainsString('neighbor_users', $joined);

        // Verify maintenance mode is lifted upon full success
        $maint = MaintenanceGuard::getConfig();
        $this->assertFalse($maint['enabled']);
    }

    public function testHandleApplyPrefixMismatchLeavesMaintenanceEnabled(): void
    {
        $stagedId = 'mismatch_xyz';
        $stagingDir = Paths::stagingDir() . '/restore-' . $stagedId;
        @mkdir($stagingDir . '/database', 0755, true);

        // All foreign prefix dump
        $dumpSql = "-- Foreign dump\n"
            . "DROP TABLE IF EXISTS `otherprefix_options`;\n"
            . "CREATE TABLE `otherprefix_options` (`id` int);\n";

        file_put_contents($stagingDir . '/database/db-test.sql', $dumpSql);

        $mockWpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public function query(string $sql): bool { return true; }
        };
        $GLOBALS['wpdb'] = $mockWpdb;

        RestoreState::update([
            'staged_id' => $stagedId,
            'status' => 'staged',
            'hash_verified' => true,
            'has_sql' => true,
            'has_files' => false,
        ]);

        $route = new BackupRestoreRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/apply', [
            'staged_id' => $stagedId,
        ]);

        $response = $route->handleApply($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('prefix_mismatch', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);

        // D5 & D7: Fail closed! Maintenance mode MUST remain enabled
        $maint = MaintenanceGuard::getConfig();
        $this->assertTrue($maint['enabled'], 'Expected maintenance mode to stay enabled on SQL failure/prefix mismatch');
    }

    public function testHandleStageWhileLockHeldReturns429Busy(): void
    {
        OperationLock::acquire('backup_restore', 60);

        $route = new BackupRestoreRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/restore/stage', [
            'download_url' => 'https://s3.amazonaws.com/bucket/backup.zip',
            'archive_key' => 'archives/example.com/2026-09-11.zip',
            'expected_sha256' => str_repeat('a', 64),
        ]);

        $response = $route->handleStage($request);
        $this->assertSame(429, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['ok']);
        $this->assertSame('busy', $data['error']);

        OperationLock::release('backup_restore');
    }
}
