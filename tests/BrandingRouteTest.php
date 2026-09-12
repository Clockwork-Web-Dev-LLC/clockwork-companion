<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\BrandingRoute;
use ClockworkCompanion\WhiteLabel\WhiteLabel;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class BrandingRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
    }

    public function testHandlePostPersistsSanitizedConfiguration(): void
    {
        $route = new BrandingRoute();
        $payload = [
            'enabled' => true,
            'company_name' => 'Vanguard Web',
            'company_url' => 'https://vanguardweb.dev',
            'support_email' => 'help@vanguardweb.dev',
            'support_url' => 'https://vanguardweb.dev/help',
            'plugin_name' => 'Vanguard Monitor',
            'plugin_description' => 'Dedicated site telemetry & health engine.',
            'menu_title' => 'Vanguard',
            'menu_icon' => 'dashicons-shield',
            'logo_url' => 'https://vanguardweb.dev/logo.png',
            'hide_plugin_row' => true,
            'hide_help_links' => true,
            'footer_text' => 'Maintained by Vanguard Web Services',
        ];

        $request = new WP_REST_Request('POST', '/clockwork/v1/branding', $payload);
        $response = $route->handlePost($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['updated']);

        $saved = get_option(WhiteLabel::OPTION_KEY);
        $this->assertIsArray($saved);
        $this->assertTrue($saved['enabled']);
        $this->assertSame('Vanguard Web', $saved['company_name']);
        $this->assertSame('Vanguard Monitor', $saved['plugin_name']);
        $this->assertSame('Vanguard', $saved['menu_title']);
        $this->assertSame('dashicons-shield', $saved['menu_icon']);
        $this->assertTrue($saved['hide_plugin_row']);
        $this->assertTrue($saved['hide_help_links']);
        $this->assertSame('Maintained by Vanguard Web Services', $saved['footer_text']);
    }

    public function testHandlePostRejectsNonArrayBody(): void
    {
        $route = new BrandingRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/branding', []);
        
        // Use reflection to mock null/invalid get_json_params
        $mockReq = $this->createMock(WP_REST_Request::class);
        $mockReq->method('get_json_params')->willReturn(null);

        $response = $route->handlePost($mockReq);
        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['ok']);
        $this->assertSame('invalid_body', $data['error']);
    }

    public function testHandleGetReturnsActiveConfiguration(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'company_name' => 'Active Brand',
            'plugin_name' => 'Active Companion',
        ]);

        $route = new BrandingRoute();
        $request = new WP_REST_Request('GET', '/clockwork/v1/branding');
        $response = $route->handleGet($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertSame('Active Brand', $data['branding']['company_name']);
        $this->assertSame('Active Companion', $data['branding']['plugin_name']);
    }

    public function testHandlePostPersistsCustomColors(): void
    {
        $route = new BrandingRoute();
        $payload = [
            'enabled' => true,
            'company_name' => 'Clockwork Web Dev',
            'primary_color' => '#2D2062',
            'accent_color' => '#7EFF83',
        ];

        $request = new WP_REST_Request('POST', '/clockwork/v1/branding', $payload);
        $response = $route->handlePost($request);

        $this->assertSame(200, $response->get_status());
        $saved = get_option(WhiteLabel::OPTION_KEY);
        $this->assertSame('#2D2062', $saved['primary_color']);
        $this->assertSame('#2D2062', $saved['primary_dark_color']);
        $this->assertSame('#7EFF83', $saved['accent_color']);
    }

    public function testHandlePostKeepsBrandTextSeparateFromMenuTitle(): void
    {
        $route = new BrandingRoute();
        $payload = [
            'enabled' => true,
            'menu_title' => 'Clockwork',
            'brand_text' => 'Companion',
            'primary_color' => '#0F172A',
            'accent_color' => '#38BDF8',
        ];

        $request = new WP_REST_Request('POST', '/clockwork/v1/branding', $payload);
        $response = $route->handlePost($request);

        $this->assertSame(200, $response->get_status());

        $saved = get_option(WhiteLabel::OPTION_KEY);
        $this->assertSame('Clockwork', $saved['menu_title']);
        $this->assertSame('Companion', $saved['brand_text']);

        $settings = WhiteLabel::getSettings();
        $this->assertSame('Clockwork', $settings['menu_title']);
        $this->assertSame('Companion', $settings['brand_text']);
    }

}
