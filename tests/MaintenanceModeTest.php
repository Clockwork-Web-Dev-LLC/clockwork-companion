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

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_CLOCKWORK_SIGNATURE'], $_SERVER['REQUEST_URI']);
        parent::tearDown();
    }
}
