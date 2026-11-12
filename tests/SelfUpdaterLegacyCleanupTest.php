<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Updates\SelfUpdater;
use PHPUnit\Framework\TestCase;

class SelfUpdaterLegacyCleanupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/cw-legacy-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/wp-content-real/plugins/clockwork-companion', 0777, true);
        mkdir($this->root.'/wp-content-real/mu-plugins/clockwork-companion', 0777, true);
        file_put_contents($this->root.'/wp-content-real/plugins/clockwork-companion/clockwork-companion.php', '<?php');
        file_put_contents($this->root.'/wp-content-real/mu-plugins/clockwork-companion.php', '<?php');
        file_put_contents($this->root.'/wp-content-real/mu-plugins/clockwork-companion/clockwork-companion.php', '<?php');
        symlink($this->root.'/wp-content-real', $this->root.'/wp-content');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function testCopyRunningFromSymlinkedMuPluginsIsNotDeleted(): void
    {
        // PHP reports the resolved path of the running file, while
        // WPMU_PLUGIN_DIR is configured through the symlink.
        $running = $this->root.'/wp-content-real/mu-plugins/clockwork-companion/clockwork-companion.php';

        SelfUpdater::cleanupLegacyMuPluginIn($running, $this->root.'/wp-content/plugins', $this->root.'/wp-content/mu-plugins');

        $this->assertFileExists($running);
        $this->assertFileExists($this->root.'/wp-content-real/mu-plugins/clockwork-companion.php');
    }

    public function testLegacyCopyIsRemovedWhenRunningFromPlugins(): void
    {
        $running = $this->root.'/wp-content/plugins/clockwork-companion/clockwork-companion.php';

        SelfUpdater::cleanupLegacyMuPluginIn($running, $this->root.'/wp-content-real/plugins', $this->root.'/wp-content/mu-plugins');

        $this->assertFileExists($running);
        $this->assertFileDoesNotExist($this->root.'/wp-content-real/mu-plugins/clockwork-companion.php');
        $this->assertDirectoryDoesNotExist($this->root.'/wp-content-real/mu-plugins/clockwork-companion');
    }

    public function testNothingIsDeletedWhenRunningFromNeitherDirectory(): void
    {
        mkdir($this->root.'/elsewhere');
        file_put_contents($this->root.'/elsewhere/clockwork-companion.php', '<?php');

        SelfUpdater::cleanupLegacyMuPluginIn($this->root.'/elsewhere/clockwork-companion.php', $this->root.'/wp-content/plugins', $this->root.'/wp-content/mu-plugins');

        $this->assertFileExists($this->root.'/wp-content-real/mu-plugins/clockwork-companion.php');
    }

    public function testKeepPluginActiveRestoresTheOriginalPositionWithoutSorting(): void
    {
        $old = ['zeta/zeta.php', SelfUpdater::PLUGIN_FILE, 'alpha/alpha.php', 'beta/beta.php'];
        $new = ['zeta/zeta.php', 'alpha/alpha.php', 'beta/beta.php'];

        $this->assertSame($old, SelfUpdater::keepPluginActive($new, $old));
    }
}
