<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Gatekeeper\Gate;
use ClockworkCompanion\Gatekeeper\Schema;
use ClockworkCompanion\Gatekeeper\Settings;
use ClockworkCompanion\Gatekeeper\Store;
use ClockworkCompanion\Plugin;
use ClockworkCompanion\Rest\GatekeeperSettingsRoute;
use ClockworkCompanion\Rest\LockoutsRoute;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

class GatekeeperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['wp_test_object_cache'] = [];
        $GLOBALS['wp_test_object_cache_expire'] = [];
        $GLOBALS['wp_test_using_ext_object_cache'] = true;
        $GLOBALS['wp_test_status_header'] = 200;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10'; // TEST-NET-3
        $_SERVER['REQUEST_URI'] = '/wp-login.php';
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testCapabilityIsAdvertised(): void
    {
        $this->assertContains('gatekeeper', Plugin::CAPABILITIES);
        $this->assertContains('lockouts', Plugin::CAPABILITIES);
        $this->assertContains('lockouts-unlock', Plugin::CAPABILITIES);
    }

    public function testSchemaEnsureInstalledIsIdempotent(): void
    {
        Schema::ensureInstalled();
        $this->assertSame(Schema::SCHEMA_VERSION, (int) get_option(Schema::VERSION_OPTION));

        // Second call no-ops cleanly
        Schema::ensureInstalled();
        $this->assertSame(Schema::SCHEMA_VERSION, (int) get_option(Schema::VERSION_OPTION));
    }

    public function testDefaultSettingsAreDisabled(): void
    {
        $this->assertFalse(Settings::enabled());
        $defaults = Settings::defaults();
        $this->assertFalse($defaults['enabled']);
        $this->assertSame(4, $defaults['threshold']);
        $this->assertSame(1200, $defaults['window_seconds']);
        $this->assertSame(1200, $defaults['lockout_seconds']);
        $this->assertSame(4, $defaults['consecutive_lockouts_for_extended']);
        $this->assertSame(86400, $defaults['extended_lockout_seconds']);
        $this->assertTrue($defaults['show_ip']);
        $this->assertTrue($defaults['show_unlock_link']);
    }

    public function testIgnoredIpsAreNeverLocked(): void
    {
        Settings::update([
            'enabled' => true,
            'ignore_ips' => ['203.0.113.50'],
            'ignore_cidrs' => ['203.0.113.128/25'],
        ]);

        $this->assertTrue(Store::isIgnored('127.0.0.1'));
        $this->assertTrue(Store::isIgnored('::1'));
        $this->assertTrue(Store::isIgnored('192.168.1.100'));
        $this->assertTrue(Store::isIgnored('10.0.0.5'));
        $this->assertTrue(Store::isIgnored('172.16.0.1'));
        $this->assertTrue(Store::isIgnored('169.254.1.1'));
        $this->assertTrue(Store::isIgnored('203.0.113.50')); // in ignore_ips
        $this->assertTrue(Store::isIgnored('203.0.113.200')); // in ignore_cidrs
        $this->assertFalse(Store::isIgnored('203.0.113.10')); // TEST-NET-3 is still a public IP
    }

    public function testIncrementFailureLocksAtThreshold(): void
    {
        Settings::update(['enabled' => true, 'threshold' => 4]);
        $ip = '203.0.113.10';

        $this->assertNull(Store::isLocked($ip));

        Store::incrementFailure($ip); // 1
        $this->assertNull(Store::isLocked($ip));

        Store::incrementFailure($ip); // 2
        $this->assertNull(Store::isLocked($ip));

        Store::incrementFailure($ip); // 3
        $this->assertNull(Store::isLocked($ip));

        Store::incrementFailure($ip); // 4 -> threshold reached!
        $locked = Store::isLocked($ip);
        $this->assertIsArray($locked);
        $this->assertSame($ip, $locked['ip']);
        $this->assertGreaterThan(0, $locked['retry_after']);
        $this->assertNotEmpty($locked['unlock_at']);
    }

    public function testResetOnSuccessClearsLockout(): void
    {
        Settings::update(['enabled' => true, 'threshold' => 1]);
        $ip = '203.0.113.10';

        Store::incrementFailure($ip);
        $this->assertNotNull(Store::isLocked($ip));

        Store::resetOnSuccess($ip);
        $this->assertNull(Store::isLocked($ip));
    }

    public function testEarlyLockoutCheckReturnsErrorForRestAndXmlRpc(): void
    {
        Settings::update(['enabled' => true]);
        $ip = '203.0.113.10';
        $_SERVER['REMOTE_ADDR'] = $ip;

        Store::lock($ip);
        $this->assertNotNull(Store::isLocked($ip));

        // REST request test
        if (! defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }

        $result = Gate::earlyLockoutCheck(null, 'testuser', 'password');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('gatekeeper_locked', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertSame(429, $data['status']);
        $this->assertArrayHasKey('retry_after', $data);
    }

    public function testEarlyLockoutCheckBypassesClockworkHmacRoutes(): void
    {
        Settings::update(['enabled' => true]);
        $ip = '203.0.113.10';
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['REQUEST_URI'] = '/wp-json/clockwork/v1/lockouts';

        Store::lock($ip);
        $this->assertNotNull(Store::isLocked($ip));

        // Calling earlyLockoutCheck on Clockwork REST endpoint must pass through
        $userObj = (object) ['ID' => 123];
        $result = Gate::earlyLockoutCheck($userObj, '', '');
        $this->assertSame($userObj, $result);
    }

    public function testSettingsRouteStoresEscapedFields(): void
    {
        $route = new GatekeeperSettingsRoute();
        $payload = [
            'enabled' => true,
            'threshold' => 5,
            'window_seconds' => 900,
            'lockout_seconds' => 1800,
            'consecutive_lockouts_for_extended' => 3,
            'extended_lockout_seconds' => 43200,
            'headline' => 'Agency Access Paused',
            'body' => 'Please wait {duration} before next attempt.',
            'support_label' => 'Service Desk',
            'support_email' => 'helpdesk@example.gov',
            'support_url' => 'https://example.gov/support',
            'show_ip' => true,
            'show_unlock_link' => true,
            'unlock_url' => 'https://clockworkwd.com/unlock',
            'ignore_ips' => ['203.0.113.99'],
            'ignore_cidrs' => ['203.0.113.0/24'],
        ];

        $request = new WP_REST_Request('POST', '/clockwork/v1/gatekeeper-settings', $payload);
        $response = $route->handle($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);

        $stored = Settings::get();
        $this->assertTrue($stored['enabled']);
        $this->assertSame(5, $stored['threshold']);
        $this->assertSame(900, $stored['window_seconds']);
        $this->assertSame('Agency Access Paused', $stored['headline']);
        $this->assertSame('helpdesk@example.gov', $stored['support_email']);
        $this->assertSame(['203.0.113.99'], $stored['ignore_ips']);
        $this->assertSame(['203.0.113.0/24'], $stored['ignore_cidrs']);
    }

    public function testLockoutsRouteWithGatekeeper(): void
    {
        Settings::update(['enabled' => true]);
        $ip = '203.0.113.10';
        Store::lock($ip);

        $route = new LockoutsRoute();

        // GET active lockouts
        $request = new WP_REST_Request('GET', '/clockwork/v1/lockouts');
        $response = $route->handle($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertNotEmpty($data['lockouts']);
        $this->assertSame($ip, $data['lockouts'][0]['ip']);
        $this->assertSame('clockwork_lockouts', $data['lockouts'][0]['source_table']);

        // DELETE single IP
        $delRequest = new WP_REST_Request('DELETE', '/clockwork/v1/lockouts');
        $delRequest->set_param('ip', $ip);
        $delResponse = $route->handleDelete($delRequest);
        $this->assertSame(200, $delResponse->get_status());
        $this->assertNull(Store::isLocked($ip));
    }

    public function testDeleteAlsoClearsLegacyLlarOption(): void
    {
        $ip = '203.0.113.10';
        update_option('limit_login_lockouts', [$ip => time() + 3600]);

        $route = new LockoutsRoute();
        $delRequest = new WP_REST_Request('DELETE', '/clockwork/v1/lockouts');
        $delRequest->set_param('ip', $ip);
        $delResponse = $route->handleDelete($delRequest);

        $this->assertSame(200, $delResponse->get_status());
        $llar = (array) get_option('limit_login_lockouts', []);
        $this->assertArrayNotHasKey($ip, $llar);
    }

    public function testFailuresWhileLockedDoNotEscalateToExtendedLockout(): void
    {
        Settings::update([
            'enabled' => true,
            'threshold' => 1,
            'lockout_seconds' => 1200,
            'consecutive_lockouts_for_extended' => 4,
            'extended_lockout_seconds' => 86400,
        ]);
        $ip = '203.0.113.10';

        Store::incrementFailure($ip);
        $locked = Store::isLocked($ip);
        $this->assertIsArray($locked);
        $this->assertLessThan(2000, $locked['retry_after']);

        for ($i = 0; $i < 20; $i++) {
            Store::incrementFailure($ip);
        }

        $still = Store::isLocked($ip);
        $this->assertIsArray($still);
        $this->assertLessThan(2000, $still['retry_after']);

        $row = $GLOBALS['wpdb']->get_row("SELECT consecutive_lockouts FROM `wp_clockwork_lockouts` WHERE ip = '{$ip}'");
        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['consecutive_lockouts']);
    }

    public function testFourthConsecutiveLockoutUsesExtendedDuration(): void
    {
        Settings::update([
            'enabled' => true,
            'lockout_seconds' => 1200,
            'consecutive_lockouts_for_extended' => 4,
            'extended_lockout_seconds' => 86400,
        ]);
        $ip = '203.0.113.10';

        for ($n = 1; $n <= 4; $n++) {
            if ($n > 1) {
                $this->expireNativeLock($ip);
            }
            Store::lock($ip);
        }

        $locked = Store::isLocked($ip);
        $this->assertIsArray($locked);
        $this->assertGreaterThan(80000, $locked['retry_after']);
    }

    public function testFirstAttemptSetsCacheTtlToWindow(): void
    {
        Settings::update(['enabled' => true, 'window_seconds' => 1200, 'threshold' => 4]);
        $ip = '203.0.113.10';

        Store::incrementFailure($ip);

        $this->assertSame(1200, $GLOBALS['wp_test_object_cache_expire']['gatekeeper:gatekeeper_attempts:'.$ip] ?? null);
    }

    public function testUnlockIpClearsLegacyLlarRetries(): void
    {
        $ip = '203.0.113.10';
        update_option('limit_login_retries', [$ip => 5]);
        update_option('limit_login_retries_valid', [$ip => time() + 3600]);

        Store::unlockIp($ip);

        $this->assertArrayNotHasKey($ip, (array) get_option('limit_login_retries', []));
        $this->assertArrayNotHasKey($ip, (array) get_option('limit_login_retries_valid', []));
    }

    private function expireNativeLock(string $ip): void
    {
        wp_cache_delete("gatekeeper_locked:{$ip}", 'gatekeeper');
        wp_cache_delete("gatekeeper_attempts:{$ip}", 'gatekeeper');
        $rows = &$GLOBALS['wpdb']->table_rows['wp_clockwork_lockouts'];
        foreach ($rows as &$row) {
            if (($row['ip'] ?? '') === $ip) {
                $row['unlock_at'] = gmdate('Y-m-d H:i:s', time() - 60);
            }
        }
        unset($row);
    }
}
