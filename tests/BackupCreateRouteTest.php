<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Backup\S3DirectUploader;
use ClockworkCompanion\Rest\BackupCreateRoute;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

class BackupCreateRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        S3DirectUploader::$testUploader = null;
    }

    protected function tearDown(): void
    {
        S3DirectUploader::$testUploader = null;
        parent::tearDown();
    }

    public function testHandleRejectsMissingUploadUrl(): void
    {
        $route = new BackupCreateRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/create', []);

        $response = $route->handle($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_upload_url', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function testHandleExecutesBackupAndStreamsToS3(): void
    {
        // Mock $wpdb
        $mockWpdb = new class {
            public string $prefix = 'wp_';
            public function get_col(string $query): array {
                return ['wp_posts', 'wp_options'];
            }
            public function get_row(string $query, string $output = 'OBJECT'): ?array {
                return ['wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint(20) NOT NULL AUTO_INCREMENT, PRIMARY KEY (`ID`))'];
            }
            public function get_var(string $query): int {
                return 2;
            }
            public function get_results(string $query, string $output = 'OBJECT'): array {
                return [
                    ['ID' => 1, 'post_title' => 'Hello World'],
                    ['ID' => 2, 'post_title' => 'Second Post'],
                ];
            }
        };
        $GLOBALS['wpdb'] = $mockWpdb;

        $uploadedData = [];
        S3DirectUploader::$testUploader = function (string $filePath, string $uploadUrl, array $headers, int $size, string $sha256) use (&$uploadedData): array {
            $uploadedData = [
                'file_exists_during_upload' => file_exists($filePath),
                'file_path' => $filePath,
                'upload_url' => $uploadUrl,
                'headers' => $headers,
                'size' => $size,
                'sha256' => $sha256,
            ];

            // Verify zip contains database/db-...sql.gz
            $zip = new \ZipArchive();
            $opened = $zip->open($filePath);
            $this->assertTrue($opened === true);
            $hasDb = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (str_starts_with($stat['name'], 'database/')) {
                    $hasDb = true;
                    break;
                }
            }
            $zip->close();
            $this->assertTrue($hasDb, 'Expected zip archive to contain database dump');

            return [
                'ok' => true,
                'http_code' => 200,
                'size_bytes' => $size,
                'sha256' => $sha256,
            ];
        };

        $route = new BackupCreateRoute();
        $payload = [
            'upload_url' => 'https://bucket.s3.amazonaws.com/archives/mysite.com/backup.zip?presigned=1',
            'headers' => [
                'x-amz-storage-class' => 'GLACIER_IR',
            ],
            'include_database' => true,
            'include_files' => false,
        ];
        $request = new WP_REST_Request('POST', '/clockwork/v1/backup/create', $payload);

        $response = $route->handle($request);

        $this->assertNotInstanceOf(WP_Error::class, $response);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertGreaterThan(0, $data['size_bytes']);
        $this->assertSame(64, strlen($data['sha256']));
        $this->assertArrayHasKey('duration_seconds', $data);

        // Verify S3DirectUploader received parameters
        $this->assertTrue($uploadedData['file_exists_during_upload']);
        $this->assertSame('https://bucket.s3.amazonaws.com/archives/mysite.com/backup.zip?presigned=1', $uploadedData['upload_url']);
        $this->assertSame(['x-amz-storage-class' => 'GLACIER_IR'], $uploadedData['headers']);

        // Verify local staging file was automatically unlinked in finally block
        $this->assertFileDoesNotExist($uploadedData['file_path'], 'Expected local staging archive to be deleted after upload');
    }
}
