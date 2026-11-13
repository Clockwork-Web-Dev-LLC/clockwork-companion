<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Updates\Runner;
use PHPUnit\Framework\TestCase;

/**
 * Upgrader double: records the order of maintenance/upgrade calls and runs
 * the upgrader_pre_install filters the way WP_Upgrader::install_package()
 * does, so the Runner's maintenance hook fires at the real point.
 */
class RunnerLoadCheckFakeUpgrader
{
    /** @var array<int, string> */
    public array $calls = [];

    /** @var array<int, mixed> */
    public array $restoreArgs = [];

    public mixed $restoreResult = true;

    public function __construct(public mixed $skin = null)
    {
    }

    public function upgrade(string $plugin): bool
    {
        $this->calls[] = 'download';
        apply_filters('upgrader_pre_install', true, ['plugin' => $plugin]);
        $this->calls[] = 'swap';
        // What a concurrent request sees mid-swap.
        $this->calls[] = 'maintenance_during_swap=' . (file_exists(ABSPATH . '.maintenance') ? 'yes' : 'no');

        return true;
    }

    public function maintenance_mode(bool $enable = false): void
    {
        $this->calls[] = 'maintenance_' . ($enable ? 'on' : 'off');
        if ($enable) {
            file_put_contents(ABSPATH . '.maintenance', '<?php $upgrading = 1767225600; ?>');
        } else {
            @unlink(ABSPATH . '.maintenance');
        }
    }

    /** @param  array<int, array<string, string>>  $temp_backups */
    public function restore_temp_backup(array $temp_backups = []): mixed
    {
        $this->calls[] = 'restore';
        $this->restoreArgs = $temp_backups;

        return $this->restoreResult;
    }
}

class UpdateRunnerLoadCheckTest extends TestCase
{
    private const SLUG = 'hello-dolly/hello.php';

    private RunnerLoadCheckFakeUpgrader $upgrader;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = ['active_plugins' => [self::SLUG]];
        $GLOBALS['wp_test_active_plugins'] = [self::SLUG];
        $GLOBALS['wp_test_filters'] = [];
        $GLOBALS['wp_test_transients'] = [];
        $GLOBALS['wp_test_plugins'] = [self::SLUG => ['Name' => 'Hello Dolly', 'Version' => '1.7.1']];
        $GLOBALS['wp_test_transients']['update_plugins'] = (object) [
            'response' => [self::SLUG => (object) ['new_version' => '1.7.2', 'package' => 'https://downloads.example.org/hello.zip']],
        ];
        $GLOBALS['wp_test_remote_get_calls'] = [];
        @unlink(ABSPATH . '.maintenance');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_test_remote_get']);
        @unlink(ABSPATH . '.maintenance');
        parent::tearDown();
    }

    private function runner(): Runner
    {
        return new Runner(function ($skin) {
            return $this->upgrader = new RunnerLoadCheckFakeUpgrader($skin);
        });
    }

    /** Answer the loopback the way wp_finalize_scraping_edited_file_errors() does. */
    private function scrapeAnswers(mixed $result): void
    {
        $GLOBALS['wp_test_remote_get'] = static function (string $url) use ($result): array {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $key = $q['wp_scrape_key'];
            // The key must be the one that lets the request through maintenance mode.
            $ok = $key === md5('1767225600') && $q['wp_scrape_nonce'] === '1767225600'
                && get_transient('scrape_key_' . $key) === '1767225600';
            $payload = $ok ? json_encode($result) : '"wrong key"';

            return [
                'response' => ['code' => 200],
                'body' => "<html>\n###### wp_scraping_result_start:{$key} ######\n{$payload}\n###### wp_scraping_result_end:{$key} ######\n",
            ];
        };
    }

    public function testActivePluginIsSwappedInMaintenanceModeAndLoadChecked(): void
    {
        $this->scrapeAnswers(true);

        $result = $this->runner()->run(self::SLUG);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertTrue($result['load_verified']);
        $this->assertSame(
            ['download', 'maintenance_on', 'swap', 'maintenance_during_swap=yes', 'maintenance_off'],
            $this->upgrader->calls
        );
        $this->assertCount(1, $GLOBALS['wp_test_remote_get_calls']);
        $this->assertFileDoesNotExist(ABSPATH . '.maintenance');
    }

    public function testFatalAfterUpdateRollsBackAndReportsFailure(): void
    {
        $this->scrapeAnswers(['type' => E_ERROR, 'message' => 'Uncaught Error: Call to undefined function x()', 'file' => 'wp-content/plugins/hello-dolly/hello.php', 'line' => 12]);

        $result = $this->runner()->run(self::SLUG);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back']);
        $this->assertFalse($result['upgrade_completed']);
        $this->assertStringStartsWith('load_failed:', $result['error']);
        $this->assertContains('restore', $this->upgrader->calls);
        $this->assertSame([['dir' => 'plugins', 'slug' => 'hello-dolly', 'src' => WP_PLUGIN_DIR]], $this->upgrader->restoreArgs);
        $this->assertSame('maintenance_off', end($this->upgrader->calls), 'maintenance ends after the rollback');
        $this->assertContains(self::SLUG, $GLOBALS['wp_test_active_plugins']);
    }

    public function testFatalWithFailedRollbackDeactivatesThePlugin(): void
    {
        $this->scrapeAnswers(['type' => E_ERROR, 'message' => 'boom', 'file' => 'x.php', 'line' => 1]);
        $runner = new Runner(function ($skin) {
            $this->upgrader = new RunnerLoadCheckFakeUpgrader($skin);
            $this->upgrader->restoreResult = new \WP_Error('fs_temp_backup_delete', 'Could not restore');

            return $this->upgrader;
        });

        $result = $runner->run(self::SLUG);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['rolled_back']);
        $this->assertTrue($result['upgrade_completed']);
        $this->assertNotContains(self::SLUG, $GLOBALS['wp_test_active_plugins']);
    }

    public function testBlockedLoopbackDoesNotFailTheUpdate(): void
    {
        $GLOBALS['wp_test_remote_get'] = static fn () => new \WP_Error('http_request_failed', 'cURL error 7');

        $result = $this->runner()->run(self::SLUG);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['load_verified']);
        $this->assertNotContains('restore', $this->upgrader->calls);
        $this->assertStringContainsString('Load check skipped', implode("\n", $result['messages']));
    }

    public function testInactivePluginIsNotPutInMaintenanceOrChecked(): void
    {
        $GLOBALS['wp_test_options']['active_plugins'] = [];
        $GLOBALS['wp_test_active_plugins'] = [];

        $result = $this->runner()->run(self::SLUG);

        $this->assertTrue($result['ok']);
        $this->assertSame(['download', 'swap', 'maintenance_during_swap=no'], $this->upgrader->calls);
        $this->assertSame([], $GLOBALS['wp_test_remote_get_calls']);
    }
}
