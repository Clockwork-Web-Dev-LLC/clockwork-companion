<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Backup\FileApplier;
use ClockworkCompanion\Backup\Paths;
use PHPUnit\Framework\TestCase;

class FileApplierTest extends TestCase
{
    private string $tempDir;
    private string $stagingDir;
    private string $liveDir;

    protected function setUp(): void
    {
        parent::setUp();
        FileApplier::$testApplier = null;

        $this->tempDir = sys_get_temp_dir() . '/cwk_files_test_' . bin2hex(random_bytes(6));
        $this->stagingDir = $this->tempDir . '/staging/wp-content';
        $this->liveDir = $this->tempDir . '/live/wp-content';
        @mkdir($this->stagingDir, 0755, true);
        @mkdir($this->liveDir, 0755, true);
    }

    protected function tearDown(): void
    {
        FileApplier::$testApplier = null;
        Paths::rmrf($this->tempDir);
        parent::tearDown();
    }

    private function stage(string $relativePath, string $content): void
    {
        $path = $this->stagingDir . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    public function testThemeAndPluginIndexPhpFilesAreCopied(): void
    {
        $this->stage('themes/mytheme/index.php', "<?php // Silence is golden.\n");
        $this->stage('themes/mytheme/style.css', "/* Theme Name: MyTheme */\n");
        $this->stage('plugins/myplugin/index.php', "<?php // Silence is golden.\n");

        $applier = new FileApplier();
        $result = $applier->apply($this->stagingDir, $this->liveDir);

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['copied_count']);
        $this->assertFileExists($this->liveDir . '/themes/mytheme/index.php');
        $this->assertFileExists($this->liveDir . '/themes/mytheme/style.css');
        $this->assertFileExists($this->liveDir . '/plugins/myplugin/index.php');
    }

    public function testClockworkBackupsDirIsStillExcluded(): void
    {
        $this->stage('clockwork-backups/index.php', "<?php // staging marker\n");
        $this->stage('clockwork-backups/restore-abc/archive.zip', 'zipbytes');
        $this->stage('themes/mytheme/style.css', "/* Theme Name: MyTheme */\n");

        $applier = new FileApplier();
        $result = $applier->apply($this->stagingDir, $this->liveDir);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['copied_count']);
        $this->assertDirectoryDoesNotExist($this->liveDir . '/clockwork-backups');
    }

    public function testCompanionDirIsDeferredButSiblingBackupDirIsNot(): void
    {
        $companionDir = $this->liveDir . '/mu-plugins/clockwork-companion';
        @mkdir($companionDir, 0755, true);

        $this->stage('mu-plugins/clockwork-companion/companion.php', "<?php // companion\n");
        $this->stage('mu-plugins/clockwork-companion/src/Deep.php', "<?php // nested companion file\n");
        $this->stage('mu-plugins/clockwork-companion-backup/old.php', "<?php // sibling, NOT the companion dir\n");

        $applier = new FileApplier();
        $result = $applier->apply($this->stagingDir, $this->liveDir, $companionDir);

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['copied_count']);
        // Only the two files actually inside the Companion dir are deferred;
        // the clockwork-companion-backup sibling must not be.
        $this->assertSame(2, $result['deferred_companion_count']);
        $this->assertFileExists($companionDir . '/companion.php');
        $this->assertFileExists($companionDir . '/src/Deep.php');
        $this->assertFileExists($this->liveDir . '/mu-plugins/clockwork-companion-backup/old.php');
    }

    public function testWpConfigInsideArchiveIsNeverCopied(): void
    {
        $this->stage('wp-config.php', "<?php define('DB_PASSWORD', 'attacker');\n");
        $this->stage('uploads/wp-config.php', "<?php // also blocked in subdirs\n");
        $this->stage('uploads/image.txt', 'image bytes');

        $applier = new FileApplier();
        $result = $applier->apply($this->stagingDir, $this->liveDir);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['copied_count']);
        $this->assertFileDoesNotExist($this->liveDir . '/wp-config.php');
        $this->assertFileDoesNotExist($this->liveDir . '/uploads/wp-config.php');
        $this->assertFileExists($this->liveDir . '/uploads/image.txt');
    }
}
