<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Backup\Paths;
use ClockworkCompanion\SecurityScans\ChecksumsRunner;
use PHPUnit\Framework\TestCase;

class ChecksumsRunnerTest extends TestCase
{
    private string $root;

    /** @var array<string, string> */
    private array $manifest = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/cwk_checksums_'.bin2hex(random_bytes(6));
        $this->core('wp-load.php', '<?php // loader');
        $this->core('wp-admin/index.php', '<?php // admin');
        $this->core('wp-includes/version.php', '<?php $wp_version = "7.0";');
    }

    protected function tearDown(): void
    {
        Paths::rmrf($this->root);
        parent::tearDown();
    }

    private function core(string $relative, string $contents): void
    {
        $this->put($relative, $contents);
        $this->manifest[$relative] = md5($contents);
    }

    private function put(string $relative, string $contents): void
    {
        @mkdir(dirname($this->root.'/'.$relative), 0755, true);
        file_put_contents($this->root.'/'.$relative, $contents);
    }

    public function testCleanInstallIsClean(): void
    {
        $result = (new ChecksumsRunner())->verifyAgainstManifest($this->manifest, $this->root, '7.0');

        $this->assertSame('clean', $result['status']);
    }

    /**
     * The PHP path only walked the manifest, so a backdoor dropped as a new
     * file in wp-includes/ or wp-admin/ was never reported.
     */
    public function testNewPhpFilesInCoreDirectoriesAreReported(): void
    {
        $this->put('wp-includes/class-wp-cache-helper.php', '<?php // dropped');
        $this->put('wp-admin/includes/x.PHTML', '<?php // dropped');
        $this->put('wp-admin/.user.ini', "auto_prepend_file=/tmp/x\n");
        $this->put('wp-admin/error_log', 'PHP Notice: ...');

        $result = (new ChecksumsRunner())->verifyAgainstManifest($this->manifest, $this->root, '7.0');

        $this->assertSame('issues_found', $result['status']);
        $this->assertSame([
            'wp-admin/.user.ini',
            'wp-admin/includes/x.PHTML',
            'wp-includes/class-wp-cache-helper.php',
        ], $result['details']['should_not_exist']);
        $this->assertSame(3, $result['modified_files_count']);
        $this->assertStringContainsString('3 unexpected', $result['summary']);
    }

    public function testKnownExtrasAreVersionAware(): void
    {
        $this->put('wp-includes/php-ai-client/src/Client.php', '<?php // library');
        $this->put('wp-includes/fonts/.htaccess', "Require all denied\n");

        $current = (new ChecksumsRunner())->verifyAgainstManifest($this->manifest, $this->root, '7.0.1');
        $older = (new ChecksumsRunner())->verifyAgainstManifest($this->manifest, $this->root, '6.9');

        $this->assertSame('clean', $current['status']);
        $this->assertSame(['wp-includes/fonts/.htaccess', 'wp-includes/php-ai-client/src/Client.php'], $older['details']['should_not_exist']);
    }

    public function testAHandlerHtaccessInWpIncludesIsReportedEvenOnNewVersions(): void
    {
        $this->put('wp-includes/images/.htaccess', "AddHandler application/x-httpd-php .png\n");

        $result = (new ChecksumsRunner())->verifyAgainstManifest($this->manifest, $this->root, '7.1');

        $this->assertSame(['wp-includes/images/.htaccess'], $result['details']['should_not_exist']);
    }
}
