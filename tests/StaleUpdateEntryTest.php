<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\ThemesRoute;
use ClockworkCompanion\Updates\Runner;
use PHPUnit\Framework\TestCase;

class StaleUpdateEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_transients'] = [];
        // Keep TransientRefresher from attempting a loopback.
        $GLOBALS['wp_test_transients'][\ClockworkCompanion\Updates\TransientRefresher::FAILURE_TRANSIENT] = 1;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_test_themes']);
        parent::tearDown();
    }

    private function theme(string $name, string $version): object
    {
        return new class($name, $version) {
            public function __construct(private string $name, private string $version)
            {
            }

            public function get(string $header): string
            {
                return $header === 'Version' ? $this->version : $this->name;
            }
        };
    }

    public function testThemeListIgnoresAnEntryForTheInstalledVersion(): void
    {
        $GLOBALS['wp_test_themes'] = [
            'stale-theme' => $this->theme('Stale', '2.0.1'),
            'fresh-theme' => $this->theme('Fresh', '1.0.0'),
        ];
        $GLOBALS['wp_test_transients']['update_themes'] = (object) [
            'last_checked' => time(),
            'response' => [
                'stale-theme' => ['theme' => 'stale-theme', 'new_version' => '2.0.1'],
                'fresh-theme' => ['theme' => 'fresh-theme', 'new_version' => '1.1.0'],
            ],
        ];

        $payload = (new ThemesRoute())->payload();
        $bySlug = array_column($payload['items'], null, 'slug');

        $this->assertFalse($bySlug['stale-theme']['update_available']);
        $this->assertTrue($bySlug['fresh-theme']['update_available']);
        $this->assertSame(1, $payload['counts']['updates_available']);
    }

    public function testOffersNewerVersion(): void
    {
        $this->assertFalse(Runner::offersNewerVersion((object) ['new_version' => '1.7.2'], '1.7.2'));
        $this->assertFalse(Runner::offersNewerVersion(['new_version' => '1.7.1'], '1.7.2'));
        $this->assertTrue(Runner::offersNewerVersion(['new_version' => '1.7.10'], '1.7.2'));
        $this->assertTrue(Runner::offersNewerVersion((object) ['package' => 'x'], '1.7.2'), 'no version: not blocked');
    }

    public function testRunnerDoesNotReinstallTheInstalledVersionFromAStaleEntry(): void
    {
        $slug = 'hello-dolly/hello.php';
        $GLOBALS['wp_test_plugins'] = [$slug => ['Name' => 'Hello Dolly', 'Version' => '1.7.2']];
        $GLOBALS['wp_test_active_plugins'] = [];
        $GLOBALS['wp_test_transients']['update_plugins'] = (object) [
            'response' => [$slug => (object) ['new_version' => '1.7.2', 'package' => 'https://downloads.example.org/hello.1.7.2.zip']],
        ];
        $built = false;

        $result = (new Runner(function () use (&$built) {
            $built = true;

            return new \stdClass();
        }))->run($slug);

        $this->assertTrue($result['ok']);
        $this->assertSame(['Plugin is already up to date.'], $result['messages']);
        $this->assertFalse($built, 'no upgrader is run');
    }
}
