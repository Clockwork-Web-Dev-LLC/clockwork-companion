<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Updates\SelfUpdater;
use PHPUnit\Framework\TestCase;

class SelfUpdaterTest extends TestCase
{
    private const GOOD_URL = 'https://github.com/Clockwork-Web-Dev-LLC/clockwork-companion/releases/download/v9.9.9/clockwork-companion.zip';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_transients'] = [];
        $GLOBALS['wp_test_filters'] = [];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tag_name' => 'v9.9.9',
            'draft' => false,
            'prerelease' => false,
            'body' => 'Notes',
            'assets' => [
                ['name' => 'clockwork-companion.zip.sha256', 'browser_download_url' => self::GOOD_URL.'.sha256'],
                ['name' => 'clockwork-companion.zip', 'browser_download_url' => self::GOOD_URL],
            ],
        ], $overrides);
    }

    public function testRegistersTheGithubUpdateFilterPluginsApiAndLifecycleGuards(): void
    {
        SelfUpdater::register();

        $this->assertArrayHasKey('update_plugins_github.com', $GLOBALS['wp_test_filters']);
        $this->assertArrayHasKey('plugins_api', $GLOBALS['wp_test_filters']);
        $this->assertArrayHasKey('plugin_action_links_'.SelfUpdater::PLUGIN_FILE, $GLOBALS['wp_test_filters']);
        $this->assertArrayHasKey('network_admin_plugin_action_links_'.SelfUpdater::PLUGIN_FILE, $GLOBALS['wp_test_filters']);
        $this->assertArrayHasKey('pre_update_option_active_plugins', $GLOBALS['wp_test_filters']);
    }

    public function testFilterActionLinksRemovesDeactivateAndDelete(): void
    {
        $links = [
            'deactivate' => '<a href="plugins.php?action=deactivate">Deactivate</a>',
            'delete' => '<a href="plugins.php?action=delete-selected">Delete</a>',
            'settings' => '<a href="admin.php?page=clockwork">Settings</a>',
        ];

        $filtered = SelfUpdater::filterActionLinks($links);

        $this->assertArrayNotHasKey('deactivate', $filtered);
        $this->assertArrayNotHasKey('delete', $filtered);
        $this->assertArrayHasKey('settings', $filtered);
    }

    public function testKeepPluginActivePreventsDeactivationWhenFileExists(): void
    {
        $old = ['akismet/akismet.php', SelfUpdater::PLUGIN_FILE];
        $new = ['akismet/akismet.php'];

        $result = SelfUpdater::keepPluginActive($new, $old);

        $this->assertContains(SelfUpdater::PLUGIN_FILE, $result);
        $this->assertContains('akismet/akismet.php', $result);
    }

    public function testNormalizeReleasePicksTheZipAssetAndStripsTheTagPrefix(): void
    {
        $release = SelfUpdater::normalizeRelease($this->payload());

        $this->assertSame('9.9.9', $release['version']);
        $this->assertSame(self::GOOD_URL, $release['package']);
    }

    public function testNormalizeReleaseRejectsDraftsPrereleasesAndBadTags(): void
    {
        $this->assertNull(SelfUpdater::normalizeRelease($this->payload(['draft' => true])));
        $this->assertNull(SelfUpdater::normalizeRelease($this->payload(['prerelease' => true])));
        $this->assertNull(SelfUpdater::normalizeRelease($this->payload(['tag_name' => 'latest'])));
    }

    public function testUpdateOnlyWhenNewer(): void
    {
        $release = SelfUpdater::normalizeRelease($this->payload());

        $update = SelfUpdater::updateFromRelease($release, '1.1.0');
        $this->assertSame('9.9.9', $update['version']);
        $this->assertSame(self::GOOD_URL, $update['package']);
        $this->assertSame('clockwork-companion/clockwork-companion.php', $update['plugin']);

        $this->assertNull(SelfUpdater::updateFromRelease($release, '9.9.9'));
        $this->assertNull(SelfUpdater::updateFromRelease($release, '10.0.0'));
    }

    public function testUntrustedPackageUrlsAreRejected(): void
    {
        foreach ([
            'http://github.com/Clockwork-Web-Dev-LLC/clockwork-companion/releases/download/v9/x.zip',
            'https://evil.example/Clockwork-Web-Dev-LLC/clockwork-companion/releases/download/v9/x.zip',
            'https://github.com/someone-else/clockwork-companion/releases/download/v9/x.zip',
            'https://github.com/Clockwork-Web-Dev-LLC/clockwork-companion/archive/main.zip',
            'https://github.com/Clockwork-Web-Dev-LLC/clockwork-companion/releases/download/v9/x.php',
            'https://github.com/Clockwork-Web-Dev-LLC/clockwork-companion/releases/download/../../other/repo/releases/download/v9/x.zip',
            'https://github.com/Clockwork-Web-Dev-LLC/clockwork-companion/releases/download/%2e%2e/%2e%2e/other/x.zip',
            'https://user@github.com/Clockwork-Web-Dev-LLC/clockwork-companion/releases/download/v9/x.zip',
            '',
        ] as $url) {
            $this->assertFalse(SelfUpdater::isTrustedPackageUrl($url), $url);
        }

        $release = ['version' => '9.9.9', 'package' => 'https://evil.example/x.zip', 'body' => ''];
        $this->assertNull(SelfUpdater::updateFromRelease($release, '1.0.0'));
    }

    public function testFilterUpdateIgnoresOtherPluginsAndUsesCachedRelease(): void
    {
        $GLOBALS['wp_test_transients'][SelfUpdater::CACHE_KEY] = SelfUpdater::normalizeRelease($this->payload());

        $this->assertFalse(SelfUpdater::filterUpdate(false, [], 'other/other.php'));

        $update = SelfUpdater::filterUpdate(false, [], SelfUpdater::PLUGIN_FILE);
        $this->assertIsArray($update);
        $this->assertSame('9.9.9', $update['version']);
    }

    public function testFailureIsCachedAsEmptyAndLeavesUpdateUntouched(): void
    {
        $GLOBALS['wp_test_transients'][SelfUpdater::CACHE_KEY] = [];

        $this->assertNull(SelfUpdater::latestRelease());
        $this->assertFalse(SelfUpdater::filterUpdate(false, [], SelfUpdater::PLUGIN_FILE));
    }

    public function testPluginsApiReturnsDetailsOnlyForThisPlugin(): void
    {
        $GLOBALS['wp_test_transients'][SelfUpdater::CACHE_KEY] = SelfUpdater::normalizeRelease($this->payload());

        $this->assertSame('x', SelfUpdater::filterPluginsApi('x', 'plugin_information', (object) ['slug' => 'other']));

        $info = SelfUpdater::filterPluginsApi(false, 'plugin_information', (object) ['slug' => 'clockwork-companion']);
        $this->assertSame('9.9.9', $info->version);
        $this->assertSame(self::GOOD_URL, $info->download_link);
    }
}
