<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Maintenance\MaintenanceGuard;
use ClockworkCompanion\Rest\MaintenanceModeRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class MaintenanceModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        unset($_SERVER['HTTP_X_CLOCKWORK_SIGNATURE'], $_SERVER['HTTP_X_CLOCKWORK_TIMESTAMP']);
    }

    public function testDefaultConfigWhenNotStored(): void
    {
        $config = MaintenanceGuard::getConfig();
        $this->assertFalse($config['enabled']);
        $this->assertSame(3600, $config['retry_after']);
        $this->assertNotEmpty($config['title']);
    }

    public function testHandleGetReturnsCurrentConfig(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, [
            'enabled' => true,
            'title' => 'Custom Maintenance',
            'retry_after' => 1800,
        ]);

        $route = new MaintenanceModeRoute();
        $response = $route->handleGet(new WP_REST_Request('GET', '/clockwork/v1/maintenance-mode'));

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['maintenance_mode']['enabled']);
        $this->assertSame('Custom Maintenance', $data['maintenance_mode']['title']);
        $this->assertSame(1800, $data['maintenance_mode']['retry_after']);
    }

    public function testHandlePostUpdatesMaintenanceConfig(): void
    {
        $route = new MaintenanceModeRoute();
        $payload = [
            'enabled' => true,
            'title' => 'Emergency System Upgrade',
            'message' => 'Back in 15 minutes.',
            'retry_after' => 900,
            'allowed_ips' => ['203.0.113.5'],
        ];
        $request = new WP_REST_Request('POST', '/clockwork/v1/maintenance-mode', $payload);
        $response = $route->handlePost($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['updated']);

        $saved = MaintenanceGuard::getConfig();
        $this->assertTrue($saved['enabled']);
        $this->assertSame('Emergency System Upgrade', $saved['title']);
        $this->assertSame('Back in 15 minutes.', $saved['message']);
        $this->assertSame(900, $saved['retry_after']);
        $this->assertSame(['203.0.113.5'], $saved['allowed_ips']);
    }

    public function testInterceptDoesNotTriggerWhenDisabled(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => false]);
        $guard = new MaintenanceGuard();
        
        // Should return cleanly without output or exit
        $guard->intercept();
        $this->assertTrue(true);
    }

    /**
     * shouldBypass() is tested directly rather than through intercept():
     * the non-bypass path renders the 503 page and exit()s, which would
     * kill the whole PHPUnit process (this literally happened — the suite
     * silently ended mid-run printing the maintenance HTML).
     */
    public function testClockworkApiPathBypassesMaintenance(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $_SERVER['REQUEST_URI'] = '/wp-json/clockwork/v1/health';

        $guard = new MaintenanceGuard();
        $this->assertTrue($guard->shouldBypass(MaintenanceGuard::getConfig()));
    }

    public function testClockworkHeadersAloneDoNotBypassMaintenance(): void
    {
        // Pre-1.36 behavior: any request carrying X-Clockwork-* headers was
        // waved through, letting anyone skip the 503 by sending junk header
        // values. The bypass is path-based now (those routes still HMAC-gate
        // themselves), so a junk header on a normal front-end URL must NOT
        // bypass.
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $_SERVER['HTTP_X_CLOCKWORK_SIGNATURE'] = 'junk';
        $_SERVER['REQUEST_URI'] = '/some-page/';

        $guard = new MaintenanceGuard();
        $this->assertFalse($guard->shouldBypass(MaintenanceGuard::getConfig()));
    }

    public function testPublicWpAdminSlugAndUnauthenticatedAjaxDoNotBypassMaintenance(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $guard = new MaintenanceGuard();

        $_SERVER['REQUEST_URI'] = '/blog/wp-admin-security-guide/';
        $this->assertFalse($guard->shouldBypass(MaintenanceGuard::getConfig()));

        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=public_form_submit';
        $this->assertFalse($guard->shouldBypass(MaintenanceGuard::getConfig()));

        $_SERVER['REQUEST_URI'] = '/wp-admin/';
        $this->assertTrue($guard->shouldBypass(MaintenanceGuard::getConfig()));
    }

    public function testOnlyActiveUnexpiredSsoNonceBypassesMaintenance(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $guard = new MaintenanceGuard();

        $nonce = str_repeat('a', 48);
        $_SERVER['REQUEST_URI'] = '/?clockwork_sso='.$nonce;
        $_GET['clockwork_sso'] = $nonce;

        // Unknown nonce must NOT bypass maintenance mode
        $this->assertFalse($guard->shouldBypass(MaintenanceGuard::getConfig()));

        // Active unexpired nonce DOES bypass so Sso\Interceptor can redeem it
        update_option('clockwork_sso_'.$nonce, [
            'user_id' => 1,
            'expires_at' => time() + 60,
            'redirect_to' => '/wp-admin/',
        ]);
        $this->assertTrue($guard->shouldBypass(MaintenanceGuard::getConfig()));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_CLOCKWORK_SIGNATURE'], $_SERVER['REQUEST_URI'], $_GET['clockwork_sso']);
        parent::tearDown();
    }

    /**
     * Plugin::boot() runs on `plugins_loaded` at priority 5, so the guard
     * must hook a later priority. Hooked at 2 it was never called and
     * maintenance mode silently did nothing.
     */
    public function testGuardHooksAfterThePriorityBootRunsAt(): void
    {
        $GLOBALS['wp_test_actions'] = [];
        $GLOBALS['wp_test_action_priorities'] = [];

        MaintenanceGuard::register();

        $this->assertCount(1, $GLOBALS['wp_test_actions']['plugins_loaded'] ?? []);
        $this->assertGreaterThan(5, $GLOBALS['wp_test_action_priorities']['plugins_loaded'][0]);

        $main = file_get_contents(dirname(__DIR__).'/clockwork-companion.php');
        $this->assertSame(
            1,
            preg_match('/->boot\(\);\s*\}, (\d+)\);/', $main, $m),
            'Could not find the plugins_loaded priority boot() is hooked at.'
        );
        $this->assertGreaterThan((int) $m[1], MaintenanceGuard::HOOK_PRIORITY);
    }

    public function testNamespaceMentionedInAQueryStringDoesNotBypassMaintenance(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $guard = new MaintenanceGuard();

        $_SERVER['REQUEST_URI'] = '/some-page/?x=/wp-json/clockwork/v1/';
        $this->assertFalse($guard->shouldBypass(MaintenanceGuard::getConfig()));

        // The query var wins over the path in WP::parse_request(), so this is
        // a request for core's posts endpoint, not for ours.
        $_SERVER['REQUEST_URI'] = '/wp-json/clockwork/v1/health?rest_route=/wp/v2/posts';
        $_GET['rest_route'] = '/wp/v2/posts';
        $this->assertFalse($guard->shouldBypass(MaintenanceGuard::getConfig()));
        unset($_GET['rest_route']);
    }

    public function testPlainPermalinkClockworkRouteBypassesMaintenance(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $_SERVER['REQUEST_URI'] = '/?rest_route=/clockwork/v1/maintenance-mode';
        $_GET['rest_route'] = '/clockwork/v1/maintenance-mode';

        $guard = new MaintenanceGuard();
        $this->assertTrue($guard->shouldBypass(MaintenanceGuard::getConfig()));
        unset($_GET['rest_route']);
    }

    /**
     * Only the real wp-admin directory and wp-login.php are exempt. A
     * front-end URL with a `wp-admin` segment is served by index.php.
     */
    public function testLookalikeAdminAndLoginPathsDoNotBypassMaintenance(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $guard = new MaintenanceGuard();

        foreach (['/x/wp-admin/?rest_route=/wp/v2/posts', '/x/wp-login.php', '/x/wp-admin/admin-ajax.php'] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertFalse($guard->shouldBypass(MaintenanceGuard::getConfig()), $uri);
        }

        foreach (['/wp-login.php', '/wp-login.php?action=lostpassword', '/wp-admin', '/wp-admin/options.php'] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertTrue($guard->shouldBypass(MaintenanceGuard::getConfig()), $uri);
        }
    }

    /**
     * admin-post.php sits in wp-admin/, which was bypassed wholesale, so any
     * anonymous admin_post_nopriv_* handler ran during maintenance.
     */
    public function testAnonymousAdminPostIsBlockedButSignedInUsersPass(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $guard = new MaintenanceGuard();
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php?action=newsletter_signup';

        $GLOBALS['wp_test_logged_in'] = false;
        $this->assertFalse($guard->shouldBypass(MaintenanceGuard::getConfig()));

        $GLOBALS['wp_test_logged_in'] = true;
        $this->assertTrue($guard->shouldBypass(MaintenanceGuard::getConfig()));

        unset($GLOBALS['wp_test_logged_in'], $_SERVER['REQUEST_URI']);
    }

    public function testCronAndXmlrpcGetTheMaintenancePage(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $guard = new MaintenanceGuard();

        foreach (['/wp-cron.php?doing_wp_cron=1', '/xmlrpc.php'] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertFalse($guard->shouldBypass(MaintenanceGuard::getConfig()), $uri);
        }

        unset($_SERVER['REQUEST_URI']);
    }

    public function testCustomRestPrefixStillReachesClockworkRoutes(): void
    {
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        $guard = new MaintenanceGuard();
        add_filter('rest_url_prefix', fn () => 'api');

        $_SERVER['REQUEST_URI'] = '/api/'.CLOCKWORK_COMPANION_NAMESPACE.'/maintenance-mode';
        $viaPrefix = $guard->shouldBypass(MaintenanceGuard::getConfig());
        $_SERVER['REQUEST_URI'] = '/wp-json/'.CLOCKWORK_COMPANION_NAMESPACE.'/maintenance-mode';
        $viaDefault = $guard->shouldBypass(MaintenanceGuard::getConfig());

        $GLOBALS['wp_test_filters']['rest_url_prefix'] = [];
        unset($_SERVER['REQUEST_URI']);

        $this->assertTrue($viaPrefix);
        $this->assertFalse($viaDefault, 'With a custom prefix, /wp-json/ is an ordinary page');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testWpCliIsNeverBlocked(): void
    {
        define('WP_CLI', true);
        update_option(MaintenanceGuard::OPTION_KEY, ['enabled' => true]);
        unset($_SERVER['REQUEST_URI']);

        $this->assertTrue((new MaintenanceGuard())->shouldBypass(MaintenanceGuard::getConfig()));
    }
}
