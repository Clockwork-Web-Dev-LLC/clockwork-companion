<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Backup\Paths;
use PHPUnit\Framework\TestCase;

class PathsTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        unset($_SERVER['DOCUMENT_ROOT']);
        foreach ($this->cleanup as $path) {
            Paths::rmrf($path);
        }
        Paths::runShutdownCleanup();
        parent::tearDown();
    }

    /**
     * Work files hold a database dump and wp-config.php. In uploads they are
     * only protected by .htaccess, which nginx ignores.
     */
    public function testPrefersADirectoryOutsideTheDocumentRoot(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = ABSPATH;
        $parent = dirname((string) realpath(ABSPATH));

        $dir = Paths::stagingDir();
        $this->cleanup[] = $dir;

        $this->assertStringStartsWith($parent . '/clockwork-backups-', $dir);
        $this->assertStringStartsNotWith((string) realpath(ABSPATH), $dir);
        $this->assertDirectoryExists($dir);
        $this->assertFileExists($dir . '/.htaccess');
    }

    public function testStaysInUploadsWhenTheParentIsInsideTheDocumentRoot(): void
    {
        // WordPress in a subdirectory of the document root.
        $_SERVER['DOCUMENT_ROOT'] = dirname((string) realpath(ABSPATH));

        $dir = Paths::stagingDir();

        $this->assertStringEndsWith('/clockwork-backups', $dir);
        foreach (['index.php', 'index.html', '.htaccess', 'web.config'] as $stub) {
            $this->assertFileExists($dir . '/' . $stub);
        }
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($dir . '/.htaccess'));
    }

    public function testDeletedProtectionFilesAreRecreated(): void
    {
        $dir = Paths::stagingDir();
        @unlink($dir . '/.htaccess');
        @unlink($dir . '/index.php');

        Paths::stagingDir();

        $this->assertFileExists($dir . '/.htaccess');
        $this->assertFileExists($dir . '/index.php');
    }

    public function testSweepUsesShortAgesForBackupTempFilesAndKeepsWhatIsInUse(): void
    {
        $dir = Paths::stagingDir();
        $make = function (string $name, int $age, bool $isDir = false) use ($dir): string {
            $path = $dir . '/' . $name;
            $isDir ? @mkdir($path, 0755, true) : file_put_contents($path, 'x');
            touch($path, time() - $age);
            $this->cleanup[] = $path;

            return $path;
        };

        $oldDump = $make('db-' . Paths::token() . '.sql.gz', 2 * 3600);
        $freshZip = $make('backup-' . Paths::token() . '.zip', 60);
        $recentStage = $make('restore-' . Paths::token(), 2 * 3600, true);
        $oldStage = $make('restore-' . Paths::token(), 2 * 86400, true);
        $keptPartial = $make('restore-dl-' . Paths::token() . '.zip.part', 2 * 86400);

        Paths::sweepStale(null, [basename($keptPartial)]);

        $this->assertFileDoesNotExist($oldDump);
        $this->assertFileExists($freshZip);
        $this->assertDirectoryExists($recentStage);
        $this->assertDirectoryDoesNotExist($oldStage);
        $this->assertFileExists($keptPartial);
    }

    public function testShutdownCleanupRemovesRegisteredFilesUnlessForgotten(): void
    {
        $dir = Paths::stagingDir();
        $dead = $dir . '/db-' . Paths::token() . '.sql.gz';
        $kept = $dir . '/restore-' . Paths::token();
        file_put_contents($dead, 'partial dump');
        @mkdir($kept . '/database', 0755, true);
        $this->cleanup[] = $kept;

        Paths::removeOnShutdown([$dead, $kept]);
        Paths::forgetOnShutdown([$kept]);
        Paths::runShutdownCleanup();

        $this->assertFileDoesNotExist($dead);
        $this->assertDirectoryExists($kept);
    }

    public function testTokensAreRandom128Bit(): void
    {
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', Paths::token());
        $this->assertNotSame(Paths::token(), Paths::token());
    }
}
