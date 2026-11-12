<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Updates\SelfUpdater;
use PHPUnit\Framework\TestCase;
use WP_Error;

class SelfUpdaterChecksumTest extends TestCase
{
    private const ZIP = 'https://github.com/Clockwork-Web-Dev-LLC/clockwork-companion/releases/download/v9.9.9/clockwork-companion.zip';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_transients'] = [];
        $this->file = tempnam(sys_get_temp_dir(), 'cw-pkg');
        file_put_contents($this->file, 'package-bytes');
        $file = $this->file;
        $GLOBALS['wp_test_download_url'] = static fn () => $file;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_test_download_url'], $GLOBALS['wp_test_remote_get']);
        @unlink($this->file);
        parent::tearDown();
    }

    private function cacheRelease(bool $withChecksum): void
    {
        $assets = [['name' => 'clockwork-companion.zip', 'browser_download_url' => self::ZIP]];
        if ($withChecksum) {
            $assets[] = ['name' => 'clockwork-companion.zip.sha256', 'browser_download_url' => self::ZIP.'.sha256'];
        }
        $GLOBALS['wp_test_transients'][SelfUpdater::CACHE_KEY] = SelfUpdater::normalizeRelease([
            'tag_name' => 'v9.9.9', 'draft' => false, 'prerelease' => false, 'body' => '', 'assets' => $assets,
        ]);
    }

    private function checksumFileSays(string $hash): void
    {
        $GLOBALS['wp_test_remote_get'] = static fn () => ['response' => ['code' => 200], 'body' => $hash."  clockwork-companion.zip\n"];
    }

    public function testMatchingChecksumHandsTheVerifiedFileToTheUpgrader(): void
    {
        $this->cacheRelease(true);
        $this->checksumFileSays(hash('sha256', 'package-bytes'));

        $this->assertSame($this->file, SelfUpdater::verifyPackageDownload(false, self::ZIP));
    }

    public function testMismatchIsRefusedAndTheDownloadDeleted(): void
    {
        $this->cacheRelease(true);
        $this->checksumFileSays(hash('sha256', 'something else'));

        $result = SelfUpdater::verifyPackageDownload(false, self::ZIP);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('clockwork_checksum_mismatch', $result->get_error_code());
        $this->assertFileDoesNotExist($this->file);
    }

    public function testUnfetchableChecksumIsRefused(): void
    {
        $this->cacheRelease(true);
        $GLOBALS['wp_test_remote_get'] = static fn () => new WP_Error('http_request_failed', 'timeout');

        $result = SelfUpdater::verifyPackageDownload(false, self::ZIP);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('clockwork_checksum_unavailable', $result->get_error_code());
    }

    public function testReleasesWithoutAChecksumAndOtherPackagesAreLeftToCore(): void
    {
        $this->cacheRelease(false);
        $this->assertFalse(SelfUpdater::verifyPackageDownload(false, self::ZIP));

        $this->cacheRelease(true);
        $this->assertFalse(SelfUpdater::verifyPackageDownload(false, 'https://downloads.wordpress.org/plugin/akismet.zip'));
        $this->assertSame('/already/downloaded.zip', SelfUpdater::verifyPackageDownload('/already/downloaded.zip', self::ZIP));
    }

    public function testRefreshLoopbackKeepsTheReleaseCache(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/src/Admin/UpdatesRefreshAjaxHandler.php');

        $this->assertStringNotContainsString('delete_transient(', $source);
    }
}
