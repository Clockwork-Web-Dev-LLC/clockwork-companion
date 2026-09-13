<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\EnvironmentRoute;
use ClockworkCompanion\Rest\SnapshotRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class EnvironmentRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.4';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);
        parent::tearDown();
    }

    public function testRegisterRegistersRoute(): void
    {
        $route = new EnvironmentRoute();
        $route->register();

        $this->assertArrayHasKey(
            CLOCKWORK_COMPANION_NAMESPACE . '/environment',
            $GLOBALS['wp_test_routes']
        );
    }

    public function testHandleReturnsEnvironmentTelemetry(): void
    {
        $route = new EnvironmentRoute();
        $request = new WP_REST_Request('GET', '/clockwork-companion/v1/environment');

        $response = $route->handle($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertIsArray($data['php']);
        $this->assertSame(PHP_VERSION, $data['php']['version']);
        $this->assertArrayHasKey('memory_limit', $data['php']);
        $this->assertArrayHasKey('critical_extensions', $data['php']);

        $this->assertIsArray($data['database']);
        $this->assertSame('8.0.36', $data['database']['server_version']);
        $this->assertSame('wp_', $data['database']['prefix']);

        $this->assertIsArray($data['wordpress']);
        $this->assertTrue($data['wordpress']['is_ssl']);
        $this->assertSame('https://example.com', $data['wordpress']['home_url']);

        $this->assertIsArray($data['server']);
        $this->assertSame('nginx', $data['server']['web_server']);

        $this->assertIsArray($data['storage']);
        $this->assertIsArray($data['object_cache']);
    }

    public function testSummaryPayloadReturnsConciseTelemetry(): void
    {
        $route = new EnvironmentRoute();
        $summary = $route->summaryPayload();

        $this->assertSame(PHP_VERSION, $summary['php_version']);
        $this->assertSame('8.0.36', $summary['db_version']);
        $this->assertSame('nginx', $summary['web_server']);
        $this->assertArrayHasKey('object_cache', $summary);
    }

    public function testSnapshotIncludesEnvironmentPayload(): void
    {
        $snapshot = new SnapshotRoute();
        $request = new WP_REST_Request('GET', '/clockwork-companion/v1/snapshot');
        $response = $snapshot->handle($request);
        $data = $response->get_data();

        $this->assertArrayHasKey('environment', $data);
        $this->assertSame(PHP_VERSION, $data['environment']['php_version']);
        $this->assertSame('8.0.36', $data['environment']['db_version']);
        $this->assertSame('nginx', $data['environment']['web_server']);
    }
}
