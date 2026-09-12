<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Backup\ArchiveExtractor;
use ClockworkCompanion\Backup\Paths;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ArchiveExtractorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        ArchiveExtractor::$testExtractor = null;
        $this->tempDir = sys_get_temp_dir() . '/cwk_zip_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        ArchiveExtractor::$testExtractor = null;
        Paths::rmrf($this->tempDir);
        parent::tearDown();
    }

    public function testIsUnsafeEntryNameFlagsTraversalAbsoluteAndDrivePaths(): void
    {
        $this->assertTrue(ArchiveExtractor::isUnsafeEntryName('../evil.php'));
        $this->assertTrue(ArchiveExtractor::isUnsafeEntryName('wp-content/../../evil.php'));
        $this->assertTrue(ArchiveExtractor::isUnsafeEntryName('..\\evil.php'));
        $this->assertTrue(ArchiveExtractor::isUnsafeEntryName('/etc/passwd'));
        $this->assertTrue(ArchiveExtractor::isUnsafeEntryName('C:evil.php'));
        $this->assertTrue(ArchiveExtractor::isUnsafeEntryName('c:\\windows\\evil.php'));

        $this->assertFalse(ArchiveExtractor::isUnsafeEntryName('wp-content/themes/mytheme/index.php'));
        $this->assertFalse(ArchiveExtractor::isUnsafeEntryName('database/db.sql'));
        $this->assertFalse(ArchiveExtractor::isUnsafeEntryName('files/a..b/name..txt'));
    }

    public function testExtractRejectsArchiveContainingTraversalEntry(): void
    {
        $zipPath = $this->tempDir . '/evil.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('safe.txt', 'safe content');
        $addedEvil = $zip->addFromString('../evil.php', "<?php // escape attempt\n");
        $zip->close();

        // Some libzip builds refuse to add traversal names; the helper coverage above
        // guards the validation logic itself in that case.
        $check = new ZipArchive();
        $this->assertTrue($check->open($zipPath) === true);
        $evilPresent = $check->locateName('../evil.php', ZipArchive::FL_UNCHANGED) !== false
            || $check->locateName('../evil.php') !== false;
        $check->close();

        if (! $addedEvil || ! $evilPresent) {
            $this->markTestSkipped('ZipArchive on this platform refuses traversal entry names; validated via isUnsafeEntryName instead.');
        }

        $destDir = $this->tempDir . '/extract';
        $extractor = new ArchiveExtractor();
        $result = $extractor->extract($zipPath, $destDir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unsafe_archive', (string) ($result['error'] ?? ''));
        $this->assertFileDoesNotExist(dirname($destDir) . '/evil.php');
        $this->assertFileDoesNotExist($destDir . '/safe.txt', 'Nothing should be extracted from an unsafe archive');
    }

    public function testExtractAcceptsSafeArchive(): void
    {
        $zipPath = $this->tempDir . '/safe.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('wp-content/themes/mytheme/index.php', "<?php // Silence is golden.\n");
        $zip->addFromString('database/db.sql', "CREATE TABLE `wp_options` (`id` int);\n");
        $zip->close();

        $destDir = $this->tempDir . '/extract';
        $extractor = new ArchiveExtractor();
        $result = $extractor->extract($zipPath, $destDir);

        $this->assertTrue($result['ok']);
        $this->assertFileExists($destDir . '/wp-content/themes/mytheme/index.php');
        $this->assertFileExists($destDir . '/database/db.sql');
    }
}
