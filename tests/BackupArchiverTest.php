<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Backup\BackupArchiver;
use ClockworkCompanion\Backup\DatabaseDumper;
use PHPUnit\Framework\TestCase;

class BackupArchiverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/cwk_companion_archiver_test_'.bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->tempDir);
        @unlink(WP_CONTENT_DIR.'/uploads/2026/10/photo.jpg');
        @rmdir(WP_CONTENT_DIR.'/uploads/2026/10');
        @rmdir(WP_CONTENT_DIR.'/uploads/2026');
        @unlink(WP_CONTENT_DIR.'/plugins/my-cool-plugin/plugin.php');
        @rmdir(WP_CONTENT_DIR.'/plugins/my-cool-plugin');
        @unlink(WP_CONTENT_DIR.'/plugins/another-addon/index.php');
        @rmdir(WP_CONTENT_DIR.'/plugins/another-addon');
        @unlink(WP_CONTENT_DIR.'/themes/my-custom-theme/style.css');
        @rmdir(WP_CONTENT_DIR.'/themes/my-custom-theme');
        @unlink(WP_CONTENT_DIR.'/cache/page-cache/cached.html');
        @rmdir(WP_CONTENT_DIR.'/cache/page-cache');
        @rmdir(WP_CONTENT_DIR.'/cache');
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testManifestCategorizesUploadsPluginsThemes(): void
    {
        $contentDir = WP_CONTENT_DIR;
        @mkdir($contentDir.'/uploads/2026/10', 0755, true);
        @file_put_contents($contentDir.'/uploads/2026/10/photo.jpg', 'fake-image-bytes-123456');

        @mkdir($contentDir.'/plugins/my-cool-plugin', 0755, true);
        @file_put_contents($contentDir.'/plugins/my-cool-plugin/plugin.php', '<?php // plugin');

        @mkdir($contentDir.'/plugins/another-addon', 0755, true);
        @file_put_contents($contentDir.'/plugins/another-addon/index.php', '<?php // addon');

        @mkdir($contentDir.'/themes/my-custom-theme', 0755, true);
        @file_put_contents($contentDir.'/themes/my-custom-theme/style.css', '/* Theme Name: Custom */');

        // Excluded folder
        @mkdir($contentDir.'/cache/page-cache', 0755, true);
        @file_put_contents($contentDir.'/cache/page-cache/cached.html', 'cached');

        // Fake database dump
        $dbDump = $this->tempDir.'/db.sql.gz';
        @file_put_contents($dbDump, 'fake-sql-gz-bytes');

        $zipOutput = $this->tempDir.'/output.zip';

        $archiver = new BackupArchiver();
        $dbStats = [
            'tables_count' => 5,
            'total_rows' => 120,
            'raw_bytes' => 50000,
            'size_bytes' => 12000,
            'tables' => [
                ['name' => 'wp_posts', 'rows' => 20, 'size_bytes' => 4000],
            ],
        ];

        $result = $archiver->createArchive($zipOutput, $dbDump, true, [], $dbStats);

        $this->assertTrue($result['ok']);
        $this->assertFileExists($zipOutput);
        $this->assertArrayHasKey('manifest', $result);

        $manifest = $result['manifest'];
        $this->assertSame(5, $manifest['database']['tables_count']);
        $this->assertSame(120, $manifest['database']['total_rows']);

        // Check file categorizations
        $files = $manifest['files'];
        $this->assertSame(1, $files['uploads']['file_count']);
        $this->assertGreaterThan(0, $files['uploads']['bytes']);

        $this->assertSame(2, $files['plugins']['count']);
        $this->assertSame(2, $files['plugins']['file_count']);
        $this->assertContains('my-cool-plugin', $files['plugins']['list']);
        $this->assertContains('another-addon', $files['plugins']['list']);

        $this->assertGreaterThanOrEqual(1, $files['themes']['count']);
        $this->assertGreaterThanOrEqual(1, $files['themes']['file_count']);
        $this->assertContains('my-custom-theme', $files['themes']['list']);

        // Check exclusions counted cache folder
        $this->assertGreaterThanOrEqual(1, $manifest['exclusions']['skipped_directories_count']);

        // Check compression summary
        $this->assertGreaterThan(0, $manifest['summary']['total_uncompressed_bytes']);
        $this->assertGreaterThan(0, $manifest['summary']['archive_size_bytes']);

        // Inspect zip contents
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipOutput) === true);
        $manifestJson = $zip->getFromName('manifest.json');
        $this->assertNotEmpty($manifestJson);
        $parsed = json_decode($manifestJson, true);
        $this->assertSame('1.0', $parsed['version']);
        $this->assertSame(2, $parsed['files']['plugins']['count']);
        $zip->close();
    }
}
