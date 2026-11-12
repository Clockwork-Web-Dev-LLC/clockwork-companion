<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\Gatekeeper\ClientIp;
use ClockworkCompanion\Gatekeeper\Gate;
use ClockworkCompanion\Gatekeeper\Schema;
use ClockworkCompanion\Gatekeeper\Settings;
use ClockworkCompanion\Gatekeeper\Store;
use ClockworkCompanion\Plugin;
use ClockworkCompanion\Rest\GatekeeperSettingsRoute;
use ClockworkCompanion\Rest\HealthRoute;
use ClockworkCompanion\Rest\LockoutsRoute;
use ClockworkCompanion\Tests\Fakes\GatekeeperWpdb;
use ClockworkCompanion\TwoFactor\LoginInterceptor;
use ClockworkCompanion\TwoFactor\Totp;
use ClockworkCompanion\TwoFactor\UserSettings;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_User;

require_once __DIR__ . '/Fakes/GatekeeperWpdb.php';

class GatekeeperTest extends TestCase
{
    private GatekeeperWpdb $db;

    /** @var mixed */
    private $previousWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_site_options'] = [];
        $GLOBALS['wp_test_blog_options'] = [];
        $GLOBALS['wp_test_is_multisite'] = false;
        $GLOBALS['wp_test_filters'] = [];
        $GLOBALS['wp_test_actions'] = [];
        $GLOBALS['wp_test_did_actions'] = [];
        $GLOBALS['wp_test_current_user'] = null;
        $GLOBALS['wp_test_current_user_can'] = true;
        $GLOBALS['wp_test_active_plugins'] = [];
        $this->previousWpdb = $GLOBALS['wpdb'];
        $this->db = new GatekeeperWpdb();
        $GLOBALS['wpdb'] = $this->db;
        $GLOBALS['wp_test_object_cache'] = [];
        $GLOBALS['wp_test_object_cache_expire'] = [];
        $GLOBALS['wp_test_using_ext_object_cache'] = true;
        $GLOBALS['wp_test_status_header'] = 200;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10'; // TEST-NET-3
        $_SERVER['REQUEST_URI'] = '/wp-login.php';
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $GLOBALS['wp']);
        Gate::resetRequestState();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        $GLOBALS['wp_test_is_multisite'] = false;
        // A stored Gatekeeper policy, network options or hooks must not leak
        // into later tests (the 2FA tests read Gatekeeper's switch).
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_site_options'] = [];
        $GLOBALS['wp_test_blog_options'] = [];
        $GLOBALS['wp_test_filters'] = [];
        $GLOBALS['wp_test_actions'] = [];
        $GLOBALS['wp_test_object_cache'] = [];
        $GLOBALS['wp_test_using_ext_object_cache'] = false;
        unset($GLOBALS['wp_test_site_ids'], $GLOBALS['wp_test_cleared_cron'], $GLOBALS['wp_test_last_redirect']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_POST['ip']);
        $_POST = [];
        $_GET = [];
        Gate::resetRequestState();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------------

    public function testCapabilityIsAdvertised(): void
    {
        $this->assertContains('gatekeeper', Plugin::CAPABILITIES);
        $this->assertContains('gatekeeper', HealthRoute::capabilities());
        $this->assertContains('lockouts', Plugin::CAPABILITIES);
        $this->assertContains('lockouts-unlock', Plugin::CAPABILITIES);
    }

    /**
     * Plugin::boot() needs a full WordPress to run, so check its source for
     * the wiring: schema in the install-state list, prune scheduled, prune
     * cleared on deactivation, gate registered, settings route registered.
     */
    public function testBootWiresGatekeeper(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Plugin.php');

        $this->assertMatchesRegularExpression('/InstallState::ensure\(\[[^\]]*Gatekeeper\\\\Schema::class/s', $source);
        $this->assertStringContainsString('Gatekeeper\\Store::maybeSchedulePrune();', $source);
        $this->assertStringContainsString("register_deactivation_hook(CLOCKWORK_COMPANION_FILE, [\\ClockworkCompanion\\Gatekeeper\\Store::class, 'unschedulePrune']);", $source);
        $this->assertStringContainsString('Gatekeeper\\Gate::register();', $source);
        $this->assertMatchesRegularExpression("/add_action\('rest_api_init'.*GatekeeperSettingsRoute\(\)\)->register\(\);.*\}\);/s", $source);
    }

    public function testSettingsRouteIsRegisteredBehindTheHmacVerifier(): void
    {
        $GLOBALS['wp_test_routes'] = [];
        (new GatekeeperSettingsRoute())->register();

        $route = $GLOBALS['wp_test_routes'][CLOCKWORK_COMPANION_NAMESPACE . '/gatekeeper-settings'] ?? null;
        $this->assertIsArray($route);
        $this->assertSame('POST', $route['methods']);
        $this->assertSame([HmacVerifier::class, 'verify'], $route['permission_callback']);

        // The verifier it names rejects an unsigned push and accepts a signed one.
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['wp_test_transients'] = [];
        $secret = Secret::ensure();
        $body = (string) json_encode(['enabled' => true]);

        $unsigned = new WP_REST_Request('POST', '/clockwork/v1/gatekeeper-settings', [], [], $body);
        $this->assertInstanceOf(WP_Error::class, HmacVerifier::verify($unsigned));

        $ts = time();
        $signed = new WP_REST_Request('POST', '/clockwork/v1/gatekeeper-settings', [], [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => hash_hmac('sha256', "POST\n/wp-json/clockwork/v1/gatekeeper-settings\n{$ts}\n{$body}", $secret),
        ], $body);
        $this->assertTrue(HmacVerifier::verify($signed));
    }

    public function testGateRegistersNothingUntilEnabled(): void
    {
        Gate::register();
        $this->assertArrayNotHasKey('authenticate', $GLOBALS['wp_test_filters']);
        $this->assertArrayNotHasKey('wp_login_failed', $GLOBALS['wp_test_actions']);

        Settings::update(['enabled' => true]);
        Gate::register();
        $this->assertCount(2, $GLOBALS['wp_test_filters']['authenticate']);
        $this->assertCount(1, $GLOBALS['wp_test_actions']['wp_login_failed']);
    }

    public function testDeactivationClearsThePruneOnEverySite(): void
    {
        $GLOBALS['wp_test_is_multisite'] = true;
        $GLOBALS['wp_test_site_ids'] = [1, 2, 3];

        Store::unschedulePrune();

        $this->assertSame(
            [[Store::PRUNE_HOOK, 'wp_'], [Store::PRUNE_HOOK, 'wp_2_'], [Store::PRUNE_HOOK, 'wp_3_']],
            $GLOBALS['wp_test_cleared_cron']
        );
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
        $this->assertSame([], $defaults['trusted_proxies']);
    }

    // ---------------------------------------------------------------------
    // Sites that ran the earlier Gatekeeper build
    // ---------------------------------------------------------------------

    /**
     * An earlier Companion build stored the same option and table (schema
     * version 1) and Clockwork set `enabled: true` on the sites it moved off
     * LLAR. Those sites must be protected again as soon as this version
     * boots, with their policy and running lockouts intact, before Clockwork
     * pushes anything.
     */
    public function testUpgradeFromTheEarlierBuildKeepsPolicyAndLockouts(): void
    {
        $GLOBALS['wp_test_using_ext_object_cache'] = false;
        update_option(Settings::OPTION, [
            'enabled' => true,
            'threshold' => 3,
            'window_seconds' => 900,
            'lockout_seconds' => 1800,
            'consecutive_lockouts_for_extended' => 4,
            'extended_lockout_seconds' => 86400,
            'headline' => 'Too many failed login attempts',
            'body' => 'Please wait {duration} before trying again.',
            'support_label' => 'IT Helpdesk',
            'support_email' => '',
            'support_url' => '',
            'show_ip' => true,
            'show_unlock_link' => false,
            'unlock_url' => '',
            'ignore_ips' => ['198.51.100.1'],
            'ignore_cidrs' => ['198.51.100.0/24'],
        ], false);
        update_option(Schema::VERSION_OPTION, 1, false);
        $future = gmdate('Y-m-d H:i:s', time() + 600);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->lockoutTables['wp_clockwork_lockouts'] = [
            '203.0.113.7' => ['id' => 1, 'ip' => '203.0.113.7', 'unlock_at' => $future, 'consecutive_lockouts' => 1, 'attempts' => 0, 'attempts_window_start' => null, 'created_at' => $now, 'updated_at' => $now],
            '203.0.113.8' => ['id' => 2, 'ip' => '203.0.113.8', 'unlock_at' => null, 'consecutive_lockouts' => 0, 'attempts' => 2, 'attempts_window_start' => $now, 'created_at' => $now, 'updated_at' => $now],
        ];

        Schema::ensureInstalled();
        Gate::register();

        $this->assertSame(Schema::SCHEMA_VERSION, (int) get_option(Schema::VERSION_OPTION));
        $this->assertTrue(Settings::enabled());
        $this->assertCount(2, $GLOBALS['wp_test_filters']['authenticate'], 'the login gate is active');
        $settings = Settings::get();
        $this->assertSame(3, $settings['threshold']);
        $this->assertSame([], $settings['trusted_proxies'], 'fields added since are filled from defaults');
        $this->assertSame(86400, $settings['consecutive_reset_seconds']);
        $this->assertNotNull(Store::isLocked('203.0.113.7'), 'a running lockout survives the upgrade');

        // The stored attempt count carries on: one more failure reaches 3.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
        $this->failLogin();
        $this->assertNotNull(Store::isLocked('203.0.113.8'));
    }

    public function testUpgradeOnMultisiteFoldsSiteTablesFromTheEarlierBuild(): void
    {
        $GLOBALS['wp_test_is_multisite'] = true;
        $GLOBALS['wp_test_site_ids'] = [1, 2];
        // The earlier build kept its schema version and policy per site.
        update_option(Schema::VERSION_OPTION, 1, false);
        $GLOBALS['wp_test_blog_options'][1][Settings::OPTION] = ['enabled' => true, 'threshold' => 5];
        $future = gmdate('Y-m-d H:i:s', time() + 600);
        $this->db->lockoutTables['wp_2_clockwork_lockouts'] = [
            '203.0.113.9' => ['id' => 1, 'ip' => '203.0.113.9', 'unlock_at' => $future, 'consecutive_lockouts' => 1, 'attempts' => 0, 'attempts_window_start' => null, 'created_at' => $future, 'updated_at' => $future],
        ];

        $this->assertTrue(Settings::enabled(), 'protected before the upgrade has run');

        Schema::ensureInstalled();

        $this->assertSame(Schema::SCHEMA_VERSION, get_site_option(Schema::VERSION_OPTION));
        $this->assertSame(5, $GLOBALS['wp_test_site_options'][Settings::OPTION]['threshold']);
        $this->assertArrayNotHasKey('wp_2_clockwork_lockouts', $this->db->lockoutTables);
        $this->assertNotNull(Store::isLocked('203.0.113.9'));
    }

    public function testUnreadableStoredPolicyFallsBackToDisabledDefaults(): void
    {
        update_option(Settings::OPTION, 'not-an-array');

        $this->assertFalse(Settings::enabled());
        $this->assertSame(Settings::defaults(), Settings::get());

        Settings::update(['enabled' => true]);
        $this->assertTrue(Settings::enabled());
    }

    // ---------------------------------------------------------------------
    // Policy, counting, lockouts
    // ---------------------------------------------------------------------

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

    public function testEarlyLockoutCheckReturnsErrorForRestAndXmlRpc(): void
    {
        Settings::update(['enabled' => true]);
        $ip = '203.0.113.10';
        $_SERVER['REMOTE_ADDR'] = $ip;

        Store::lock($ip);
        $this->assertNotNull(Store::isLocked($ip));

        $this->defineRestRequest();

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
        $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => '/clockwork/v1/lockouts']];

        Store::lock($ip);
        $this->assertNotNull(Store::isLocked($ip));

        // Calling earlyLockoutCheck on Clockwork REST endpoint must pass through
        $userObj = (object) ['ID' => 123];
        $result = Gate::earlyLockoutCheck($userObj, '', '');
        $this->assertSame($userObj, $result);
    }

    /**
     * Exempting the namespace by a substring test on the URL would let
     * `?x=/wp-json/clockwork/` on wp-login.php or xmlrpc.php switch the
     * lockout off: failures not counted and a lock ignored.
     */
    public function testNamespaceInALoginUrlDoesNotExemptTheRequest(): void
    {
        Settings::update(['enabled' => true, 'threshold' => 2]);
        $ip = '203.0.113.10';
        $_SERVER['REMOTE_ADDR'] = $ip;
        $this->defineRestRequest();

        $probes = [
            '/wp-login.php?x=/wp-json/clockwork/',
            '/wp-login.php?x=/wp-json/clockwork/v1/health',
            '/xmlrpc.php?rest_route=/clockwork/',
            '/wp-login.php?rest_route=/clockwork/v1/lockouts',
            '/wp-login.php?rest_route=%2Fclockwork%2F',
        ];

        foreach ($probes as $uri) {
            Store::unlockIp($ip);
            $_SERVER['REQUEST_URI'] = $uri;
            $_SERVER['QUERY_STRING'] = (string) parse_url($uri, PHP_URL_QUERY);
            parse_str($_SERVER['QUERY_STRING'], $_GET);

            Gate::onLoginFailed('admin');
            Gate::onLoginFailed('admin');
            $this->assertNotNull(Store::isLocked($ip), "failures were not counted for {$uri}");

            $result = Gate::earlyLockoutCheck(null, 'admin', 'guess');
            $this->assertInstanceOf(WP_Error::class, $result, "lockout was skipped for {$uri}");
            $this->assertSame('gatekeeper_locked', $result->get_error_code());
        }

        unset($_SERVER['QUERY_STRING']);
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
            'support_email' => 'helpdesk@example.org',
            'support_url' => 'https://example.org/support',
            'show_ip' => true,
            'show_unlock_link' => true,
            'unlock_url' => 'https://example.com/unlock',
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
        $this->assertSame('helpdesk@example.org', $stored['support_email']);
        $this->assertSame(['203.0.113.99'], $stored['ignore_ips']);
        $this->assertSame(['203.0.113.0/24'], $stored['ignore_cidrs']);
    }

    /** Clockwork's push carries no trusted_proxies: a local setting survives it. */
    public function testSettingsPushKeepsLocallySetTrustedProxies(): void
    {
        Settings::update(['trusted_proxies' => ['cloudflare']]);

        (new GatekeeperSettingsRoute())->handle(new WP_REST_Request('POST', '/clockwork/v1/gatekeeper-settings', ['enabled' => true, 'threshold' => 6]));

        $this->assertSame(['cloudflare'], Settings::get()['trusted_proxies']);
        $this->assertSame(6, Settings::get()['threshold']);
    }

    /**
     * Clockwork retires LLAR itself after confirming Gatekeeper enforces;
     * a policy push must not deactivate it on its own.
     */
    public function testEnablingGatekeeperLeavesLlarActive(): void
    {
        $pluginSlug = 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php';
        activate_plugin($pluginSlug);

        (new GatekeeperSettingsRoute())->handle(new WP_REST_Request('POST', '/clockwork/v1/gatekeeper-settings', ['enabled' => true]));

        $this->assertTrue(Settings::enabled());
        $this->assertTrue(is_plugin_active($pluginSlug));
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

        $this->assertSame(1, (int) $this->db->rows()[$ip]['consecutive_lockouts']);
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

        $this->assertSame(1200, $GLOBALS['wp_test_object_cache_expire']['gatekeeper:gatekeeper_attempts:' . $ip] ?? null);
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

    public function testZeroMaskCidrIsRejectedAndDoesNotAllowlistAll(): void
    {
        Settings::update([
            'enabled' => true,
            'ignore_cidrs' => ['0.0.0.0/0', '::/0'],
        ]);

        $stored = Settings::get();
        $this->assertNotContains('0.0.0.0/0', $stored['ignore_cidrs']);
        $this->assertNotContains('::/0', $stored['ignore_cidrs']);

        $this->assertFalse(Store::ipInCidr('203.0.113.1', '0.0.0.0/0'));
        $this->assertFalse(Store::isIgnored('203.0.113.1'));
    }

    public function testIpv6CidrMatching(): void
    {
        $this->assertTrue(Store::ipInCidr('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(Store::ipInCidr('2001:db9::1', '2001:db8::/32'));
    }

    public function testUnknownIpIsNotIgnoredAndIsLockedWhenThresholdMet(): void
    {
        Settings::update(['enabled' => true, 'threshold' => 2]);
        $this->assertFalse(Store::isIgnored('unknown'));

        Store::incrementFailure('unknown');
        $this->assertNull(Store::isLocked('unknown'));

        Store::incrementFailure('unknown');
        $this->assertNotNull(Store::isLocked('unknown'));
    }

    public function testEightFailuresOnThresholdFourLockOnBothStores(): void
    {
        Settings::update(['enabled' => true, 'threshold' => 4]);

        $GLOBALS['wp_test_using_ext_object_cache'] = false;
        $ipMysql = '198.51.100.101';
        for ($i = 0; $i < 8; $i++) {
            Store::incrementFailure($ipMysql);
        }
        $this->assertNotNull(Store::isLocked($ipMysql), 'database store must lock after 8 failures with threshold 4');

        $GLOBALS['wp_test_using_ext_object_cache'] = true;
        $ipCache = '198.51.100.102';
        for ($i = 0; $i < 8; $i++) {
            Store::incrementFailure($ipCache);
        }
        $this->assertNotNull(Store::isLocked($ipCache), 'object cache store must lock after 8 failures with threshold 4');
    }

    // ---------------------------------------------------------------------
    // Client address resolution
    // ---------------------------------------------------------------------

    public function testForwardingHeadersAreIgnoredWithoutTrustedProxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.8';

        $this->assertSame('203.0.113.10', ClientIp::get());
    }

    /**
     * Trusting CF-Connecting-IP from any peer lets a client pick its own
     * address: rotate it to dodge lockouts, or send a victim's to lock them out.
     */
    public function testCloudflareHeaderIsHonouredOnlyFromCloudflare(): void
    {
        Settings::update(['trusted_proxies' => ['cloudflare']]);
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';

        $_SERVER['REMOTE_ADDR'] = '173.245.48.5';
        $this->assertSame(['ip' => '198.51.100.7', 'source' => ClientIp::SOURCE_HEADER, 'peer' => '173.245.48.5'], ClientIp::resolve());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $this->assertSame('203.0.113.10', ClientIp::get());
    }

    public function testForwardedForIsReadFromTheRightPastTrustedHops(): void
    {
        Settings::update(['trusted_proxies' => "private\n198.51.100.0/24"]);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        // Leftmost entry is whatever the client chose to send.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.66, 203.0.113.44, 198.51.100.9, 10.0.0.3';

        $this->assertSame('203.0.113.44', ClientIp::get());
    }

    public function testInvalidHeaderFallsBackToThePeerNotASharedBucket(): void
    {
        Settings::update(['trusted_proxies' => ['cloudflare']]);
        $_SERVER['REMOTE_ADDR'] = '173.245.48.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';

        $this->assertSame(['ip' => '173.245.48.5', 'source' => ClientIp::SOURCE_PEER, 'peer' => '173.245.48.5'], ClientIp::resolve());
    }

    public function testTrustedProxiesFilterAndSanitising(): void
    {
        add_filter('clockwork_companion_trusted_proxies', fn (array $list) => array_merge($list, ['192.0.2.1']));
        Settings::update(['trusted_proxies' => ['0.0.0.0/0', 'bogus', '198.51.100.0/24', 'CloudFlare']]);

        $this->assertSame(['198.51.100.0/24', 'cloudflare'], Settings::get()['trusted_proxies']);
        $this->assertContains('192.0.2.1', ClientIp::trustedProxies());
        $this->assertContains('2606:4700::/32', ClientIp::trustedProxies());
    }

    /**
     * A private address relayed in a header is the client's claim: it must
     * not switch Gatekeeper off for that client.
     */
    public function testPrivateAddressFromAHeaderIsStillThrottled(): void
    {
        Settings::update(['enabled' => true, 'threshold' => 2, 'trusted_proxies' => ['cloudflare']]);
        $_SERVER['REMOTE_ADDR'] = '173.245.48.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '10.9.8.7';

        $this->failLogin();
        $this->failLogin();

        $this->assertNotNull(Store::isLocked('10.9.8.7'));
    }

    public function testPrivatePeerIsStillNeverThrottled(): void
    {
        Settings::update(['enabled' => true, 'threshold' => 1]);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';

        $this->failLogin();
        $this->failLogin();

        $this->assertNull(Store::isLocked('10.0.0.5'));
        $this->assertSame([], $this->db->rows());
    }

    // ---------------------------------------------------------------------
    // Counting
    // ---------------------------------------------------------------------

    /**
     * If a successful login wiped the address's failures, three guesses at
     * an admin followed by a login to the attacker's own account could be
     * repeated forever.
     */
    public function testSuccessfulLoginDoesNotClearEarlierFailures(): void
    {
        Settings::update(['enabled' => true, 'threshold' => 4]);

        $this->failLogin();
        $this->failLogin();
        $this->failLogin();
        $this->succeedLogin();
        $this->assertNull(Store::isLocked('203.0.113.10'));

        // The fourth guess reaches the threshold despite the login in between.
        $this->failLogin();
        $this->assertNotNull(Store::isLocked('203.0.113.10'));
    }

    public function testValidLoginsAloneNeverLockAnAddressOut(): void
    {
        foreach ([true, false] as $objectCache) {
            $GLOBALS['wp_test_using_ext_object_cache'] = $objectCache;
            Settings::update(['enabled' => true, 'threshold' => 2]);
            $_SERVER['REMOTE_ADDR'] = $objectCache ? '203.0.113.20' : '203.0.113.21';

            for ($i = 0; $i < 6; $i++) {
                $this->succeedLogin();
            }

            $this->assertNull(Store::isLocked($_SERVER['REMOTE_ADDR']));
        }
    }

    /**
     * Checking the lock at the start and counting only on failure lets every
     * request in flight before the first failure is recorded get its guess in.
     */
    public function testParallelAttemptsCannotExceedTheThreshold(): void
    {
        $this->defineRestRequest();

        foreach ([true, false] as $objectCache) {
            $GLOBALS['wp_test_using_ext_object_cache'] = $objectCache;
            Settings::update(['enabled' => true, 'threshold' => 4]);
            $_SERVER['REMOTE_ADDR'] = $objectCache ? '198.51.100.30' : '198.51.100.31';

            $results = [];
            for ($i = 0; $i < 6; $i++) {
                // All six are in flight at once: none has failed yet.
                $results[] = Gate::earlyLockoutCheck(null, 'admin', 'guess-' . $i);
            }

            $allowed = array_filter($results, fn ($r) => ! ($r instanceof WP_Error));
            $this->assertCount(4, $allowed, $objectCache ? 'object cache store' : 'database store');
            $this->assertNotNull(Store::isLocked($_SERVER['REMOTE_ADDR']));
            Gate::resetRequestState();
        }
    }

    public function testDatabaseWithoutLastInsertIdStillCounts(): void
    {
        $GLOBALS['wp_test_using_ext_object_cache'] = false;
        $this->db->rejectLastInsertId = true;
        Settings::update(['enabled' => true, 'threshold' => 3]);

        $this->assertSame(1, Store::reserveAttempt('203.0.113.10'));
        $this->assertSame(2, Store::reserveAttempt('203.0.113.10'));
        Store::releaseAttempt('203.0.113.10');
        $this->assertSame(2, Store::reserveAttempt('203.0.113.10'));
    }

    public function testIpv6AddressesInOneSlash64ShareACounter(): void
    {
        Settings::update(['enabled' => true, 'threshold' => 4]);

        foreach (['2001:db8:1:2::1', '2001:db8:1:2::2', '2001:db8:1:2:aaaa::3', '2001:db8:1:2:ffff:ffff:ffff:fffe'] as $ip) {
            Store::incrementFailure($ip);
        }

        $this->assertNotNull(Store::isLocked('2001:db8:1:2::9'));
        $this->assertNull(Store::isLocked('2001:db8:1:3::1'));
        $this->assertSame('2001:db8:1:2::', Store::bucket('2001:db8:1:2:abcd::1'));
        $this->assertSame('203.0.113.10', Store::bucket('203.0.113.10'));

        $listed = array_column(Store::activeLockouts(), 'ip');
        $this->assertSame(['2001:db8:1:2::'], $listed);

        Store::unlockIp('2001:db8:1:2::77');
        $this->assertNull(Store::isLocked('2001:db8:1:2::9'));
    }

    public function testConsecutiveLockoutsAreForgottenAfterTheResetPeriod(): void
    {
        Settings::update([
            'enabled' => true,
            'lockout_seconds' => 1200,
            'consecutive_lockouts_for_extended' => 4,
            'extended_lockout_seconds' => 86400,
            'consecutive_reset_seconds' => 86400,
        ]);
        $ip = '203.0.113.10';

        for ($n = 1; $n <= 3; $n++) {
            if ($n > 1) {
                $this->expireNativeLock($ip);
            }
            Store::lock($ip);
        }
        $this->assertSame(3, $this->db->rows()[$ip]['consecutive_lockouts']);

        // The third lockout ended two days ago.
        $this->expireNativeLock($ip);
        $this->db->lockoutTables['wp_clockwork_lockouts'][$ip]['unlock_at'] = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        Store::lock($ip);

        $this->assertSame(1, $this->db->rows()[$ip]['consecutive_lockouts']);
        $this->assertLessThan(2000, Store::isLocked($ip)['retry_after']);
    }

    public function testPruneRemovesStaleRowsEvenWithLockoutHistory(): void
    {
        Settings::update(['enabled' => true]);
        $old = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
        $this->db->lockoutTables['wp_clockwork_lockouts'] = [
            '203.0.113.1' => ['id' => 1, 'ip' => '203.0.113.1', 'unlock_at' => $old, 'consecutive_lockouts' => 3, 'attempts' => 0, 'attempts_window_start' => null, 'created_at' => $old, 'updated_at' => $old],
            '203.0.113.2' => ['id' => 2, 'ip' => '203.0.113.2', 'unlock_at' => null, 'consecutive_lockouts' => 1, 'attempts' => 2, 'attempts_window_start' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')],
        ];

        Store::prune();

        $this->assertSame(['203.0.113.2'], array_keys($this->db->rows()));
    }

    public function testConcurrentLockCallsCountAsOneLockout(): void
    {
        Settings::update(['enabled' => true]);
        $GLOBALS['wp_test_using_ext_object_cache'] = false;

        Store::lock('203.0.113.10');
        Store::lock('203.0.113.10');

        $this->assertSame(1, $this->db->rows()['203.0.113.10']['consecutive_lockouts']);
    }

    // ---------------------------------------------------------------------
    // Two-factor challenge
    // ---------------------------------------------------------------------

    /**
     * A bad 2FA code fires wp_login_failed; Gatekeeper counts it against the
     * address exactly once. The correct password that led to the challenge
     * is not counted at all.
     */
    public function testBadTwoFactorCodeIsCountedExactlyOnce(): void
    {
        $GLOBALS['wp_test_using_ext_object_cache'] = false;
        Settings::update(['enabled' => true, 'threshold' => 4]);
        Gate::register();
        [$userId, $wrongCode] = $this->enrolTwoFactorUser();

        // Password step: the right password is reserved, then handed back.
        $this->succeedLogin();
        $this->assertSame(0, (int) $this->db->rows()['203.0.113.10']['attempts']);

        // Challenge step (a separate request in WordPress).
        Gate::resetRequestState();
        $this->assertSame('bad', LoginInterceptor::verifyCode($userId, $wrongCode));

        $this->assertSame(1, did_action('wp_login_failed'));
        $this->assertSame(1, (int) $this->db->rows()['203.0.113.10']['attempts']);
    }

    public function testTwoFactorCodesAreRefusedFromALockedAddress(): void
    {
        Settings::update(['enabled' => true]);
        [$userId] = $this->enrolTwoFactorUser();
        Store::lock('203.0.113.10');

        $code = Totp::code((string) Totp::base32Decode((string) get_user_meta($userId, UserSettings::META_SECRET, true)), time());
        $this->assertSame('locked', LoginInterceptor::verifyCode($userId, $code));

        $_SERVER['REMOTE_ADDR'] = '203.0.113.11';
        $this->assertSame('ok', LoginInterceptor::verifyCode($userId, $code));
    }

    // ---------------------------------------------------------------------
    // /lockouts
    // ---------------------------------------------------------------------

    public function testLockoutsRouteListsAndClearsGatekeeperLockouts(): void
    {
        Settings::update(['enabled' => true]);
        $ip = '203.0.113.10';
        Store::lock($ip);
        Store::lock('2001:db8:5:6::1');

        $route = new LockoutsRoute();

        $data = $route->handle(new WP_REST_Request('GET', '/clockwork/v1/lockouts'))->get_data();
        $this->assertTrue($data['ok']);
        $this->assertSame([$ip, '2001:db8:5:6::'], array_column($data['lockouts'], 'ip'));
        $this->assertSame('clockwork_lockouts', $data['lockouts'][0]['source_table']);
        $this->assertNotNull($data['lockouts'][0]['unlock_at']);

        $delRequest = new WP_REST_Request('DELETE', '/clockwork/v1/lockouts');
        $delRequest->set_param('ip', $ip);
        $del = $route->handleDelete($delRequest)->get_data();
        $this->assertTrue($del['ok']);
        $this->assertTrue($del['was_locked']);
        $this->assertNull(Store::isLocked($ip));
        $this->assertNotNull(Store::isLocked('2001:db8:5:6::1'));

        $all = $route->handleDelete(new WP_REST_Request('DELETE', '/clockwork/v1/lockouts'))->get_data();
        $this->assertSame(1, $all['cleared']);
        $this->assertNull(Store::isLocked('2001:db8:5:6::1'));
        $this->assertSame([], $route->handle(new WP_REST_Request('GET', '/clockwork/v1/lockouts'))->get_data()['lockouts']);
    }

    /** Lockouts recorded while enabled stay visible (and clearable) after it is switched off. */
    public function testLockoutsRouteListsGatekeeperRowsWhileDisabled(): void
    {
        Store::lock('203.0.113.10');
        $this->assertFalse(Settings::enabled());

        $data = (new LockoutsRoute())->handle(new WP_REST_Request('GET', '/clockwork/v1/lockouts'))->get_data();

        $this->assertSame(['203.0.113.10'], array_column($data['lockouts'], 'ip'));
    }

    public function testDeleteAlsoClearsLegacyLlarOption(): void
    {
        $ip = '203.0.113.10';
        update_option('limit_login_lockouts', [$ip => time() + 3600]);

        $route = new LockoutsRoute();
        $delRequest = new WP_REST_Request('DELETE', '/clockwork/v1/lockouts');
        $delRequest->set_param('ip', $ip);
        $del = $route->handleDelete($delRequest)->get_data();

        $this->assertTrue($del['was_locked']);
        $this->assertArrayNotHasKey($ip, (array) get_option('limit_login_lockouts', []));
    }

    // ---------------------------------------------------------------------
    // Multisite
    // ---------------------------------------------------------------------

    public function testMultisiteUsesOneNetworkTableSettingAndCacheGroup(): void
    {
        $GLOBALS['wp_test_is_multisite'] = true;
        $this->db->prefix = 'wp_3_';

        Settings::update(['enabled' => true, 'threshold' => 2]);
        $this->assertTrue(Settings::enabled());
        $this->assertTrue($GLOBALS['wp_test_site_options'][Settings::OPTION]['enabled']);
        $this->assertArrayNotHasKey(Settings::OPTION, $GLOBALS['wp_test_options']);
        $this->assertSame('wp_clockwork_lockouts', Schema::tableName());

        Store::incrementFailure('203.0.113.10');
        Store::incrementFailure('203.0.113.10');

        // Seen from another subsite.
        $this->db->prefix = 'wp_5_';
        $this->assertNotNull(Store::isLocked('203.0.113.10'));
        $this->assertArrayHasKey(Store::CACHE_GROUP, $GLOBALS['wp_test_global_cache_groups'] ?? []);
    }

    public function testMultisiteUpgradeMovesSiteTablesAndPolicyToTheNetwork(): void
    {
        $GLOBALS['wp_test_is_multisite'] = true;
        $GLOBALS['wp_test_site_ids'] = [1, 2];
        $GLOBALS['wp_test_blog_options'][1][Settings::OPTION] = ['enabled' => true, 'threshold' => 6];
        $future = gmdate('Y-m-d H:i:s', time() + 600);
        $past = gmdate('Y-m-d H:i:s', time() - 600);
        $this->db->lockoutTables['wp_2_clockwork_lockouts'] = [
            '203.0.113.7' => ['id' => 1, 'ip' => '203.0.113.7', 'unlock_at' => $future, 'consecutive_lockouts' => 2, 'attempts' => 0, 'attempts_window_start' => null, 'created_at' => $past, 'updated_at' => $past],
            '203.0.113.8' => ['id' => 2, 'ip' => '203.0.113.8', 'unlock_at' => $past, 'consecutive_lockouts' => 1, 'attempts' => 0, 'attempts_window_start' => null, 'created_at' => $past, 'updated_at' => $past],
        ];

        // Before the upgrade, the main site's old policy is what applies.
        $this->assertSame(6, Settings::get()['threshold']);

        Schema::ensureInstalled();

        $this->assertSame(2, get_site_option(Schema::VERSION_OPTION));
        $this->assertArrayNotHasKey('wp_2_clockwork_lockouts', $this->db->lockoutTables);
        $this->assertSame(['203.0.113.7'], array_keys($this->db->rows()));
        $this->assertSame(6, $GLOBALS['wp_test_site_options'][Settings::OPTION]['threshold']);
        $this->assertNotNull(Store::isLocked('203.0.113.7'));
    }

    // ---------------------------------------------------------------------

    private function expireNativeLock(string $ip): void
    {
        wp_cache_delete("gatekeeper_locked:{$ip}", 'gatekeeper');
        wp_cache_delete("gatekeeper_attempts:{$ip}", 'gatekeeper');
        $this->db->lockoutTables['wp_clockwork_lockouts'][Store::bucket($ip)]['unlock_at'] = gmdate('Y-m-d H:i:s', time() - 60);
    }

    private function failLogin(): void
    {
        $this->defineRestRequest();
        Gate::earlyLockoutCheck(null, 'admin', 'wrong');
        Gate::onLoginFailed('admin');
    }

    private function succeedLogin(): void
    {
        $user = new WP_User('member@example.com', 7, 'member');
        $result = Gate::earlyLockoutCheck(null, 'member', 'right');
        Gate::releaseIfAuthenticated($result instanceof WP_Error ? $result : $user, 'member', 'right');
    }

    private function defineRestRequest(): void
    {
        if (! defined('REST_REQUEST')) {
            define('REST_REQUEST', true); // a locked request returns WP_Error instead of exit()
        }
    }

    /**
     * @return array{0: int, 1: string} user id and a code that is wrong now
     */
    private function enrolTwoFactorUser(): array
    {
        $userId = 42;
        $GLOBALS['wp_test_users'] = [$userId => new WP_User('jane@example.com', $userId, 'jane', ['administrator'])];
        $GLOBALS['wp_test_user_meta'] = [];
        $secret = Totp::generateSecret();
        update_user_meta($userId, UserSettings::META_SECRET, $secret);
        update_user_meta($userId, UserSettings::META_ENABLED, '1');
        $current = Totp::code((string) Totp::base32Decode($secret), time());

        return [$userId, $current === '000000' ? '111111' : '000000'];
    }
}
