<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Pages\UpdateCoveragePage;
use ClockworkCompanion\Plugin;
use ClockworkCompanion\Rest\UpdateExceptionsRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class UpdateExceptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_current_user'] = null;
        $GLOBALS['pagenow'] = 'index.php';
    }

    public function testCapabilityIsAdvertised(): void
    {
        $this->assertContains('update-exceptions', Plugin::CAPABILITIES);
    }

    public function testRouteStoresExceptionsInOptions(): void
    {
        $route = new UpdateExceptionsRoute();
        $payload = [
            'generated_at' => '2026-09-19T06:20:00Z',
            'site_domain' => 'example.com',
            'items' => [
                [
                    'kind' => 'plugin',
                    'slug' => 'broken-seo',
                    'name' => 'Broken SEO',
                    'stopped_at' => '2026-09-19',
                    'failure_count' => 5,
                    'from_version' => '3.2.0',
                    'attempted_version' => '3.2.1',
                    'reason_public' => 'Automatic updates did not complete after 5 attempts.',
                    'status' => 'paused',
                ],
            ],
        ];

        $request = new WP_REST_Request('POST', '/clockwork/v1/update-exceptions', $payload);
        $response = $route->handle($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);

        $saved = get_option(UpdateCoveragePage::OPTION);
        $this->assertIsArray($saved);
        $this->assertSame('example.com', $saved['site_domain']);
        $this->assertCount(1, $saved['items']);
        $this->assertSame('broken-seo', $saved['items'][0]['slug']);
        $this->assertSame('Broken SEO', $saved['items'][0]['name']);
        $this->assertSame(5, $saved['items'][0]['failure_count']);
        $this->assertSame('3.2.0', $saved['items'][0]['from_version']);
        $this->assertSame('3.2.1', $saved['items'][0]['attempted_version']);
    }

    public function testRouteClearsExceptionsWhenItemsEmpty(): void
    {
        // First seed with an item
        update_option(UpdateCoveragePage::OPTION, [
            'generated_at' => '2026-09-18T00:00:00Z',
            'site_domain' => 'example.com',
            'items' => [
                ['slug' => 'old-plugin', 'name' => 'Old Plugin'],
            ],
        ]);

        $route = new UpdateExceptionsRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/update-exceptions', [
            'generated_at' => '2026-09-19T06:20:00Z',
            'site_domain' => 'example.com',
            'items' => [],
        ]);

        $response = $route->handle($request);
        $this->assertSame(200, $response->get_status());

        $saved = get_option(UpdateCoveragePage::OPTION);
        $this->assertIsArray($saved);
        $this->assertEmpty($saved['items']);
    }

    public function testRouteRejectsNonArrayBody(): void
    {
        $route = new UpdateExceptionsRoute();
        $mockReq = $this->createMock(WP_REST_Request::class);
        $mockReq->method('get_json_params')->willReturn(null);

        $response = $route->handle($mockReq);
        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['ok']);
        $this->assertSame('invalid_body', $data['error']);
    }

    public function testPageRendersEmptyStateWhenNoExceptions(): void
    {
        update_option(UpdateCoveragePage::OPTION, [
            'generated_at' => '2026-09-19T06:20:00Z',
            'site_domain' => 'example.com',
            'items' => [],
        ]);

        ob_start();
        UpdateCoveragePage::renderBody();
        $output = ob_get_clean();

        $this->assertStringContainsString('Automatic plugin and theme updates are on for this site when a care plan is active. Nothing is currently paused.', $output);
        $this->assertStringContainsString('All updates operational', $output);
    }

    public function testPageRendersItemsTableWhenExceptionsExist(): void
    {
        update_option(UpdateCoveragePage::OPTION, [
            'generated_at' => '2026-09-19T06:20:00Z',
            'site_domain' => 'example.com',
            'items' => [
                [
                    'kind' => 'plugin',
                    'slug' => 'broken-seo',
                    'name' => 'Broken SEO',
                    'stopped_at' => '2026-09-19',
                    'failure_count' => 5,
                    'from_version' => '3.2.0',
                    'attempted_version' => '3.2.1',
                    'reason_public' => 'Automatic updates did not complete after 5 attempts.',
                    'status' => 'paused',
                ],
            ],
        ]);

        ob_start();
        UpdateCoveragePage::renderBody();
        $output = ob_get_clean();

        $this->assertStringContainsString('Paused automatic updates', $output);
        $this->assertStringContainsString('Broken SEO', $output);
        $this->assertStringContainsString('broken-seo', $output);
        $this->assertStringContainsString('3.2.0 &rarr; 3.2.1', $output);
        $this->assertStringContainsString('2026-09-19', $output);
        $this->assertStringContainsString('Automatic updates did not complete after 5 attempts.', $output);
        $this->assertStringContainsString('Manual updates remain available', $output);
    }

    public function testPluginsNoticeRendersOnlyOnPluginsPageWithActiveItems(): void
    {
        update_option(UpdateCoveragePage::OPTION, [
            'generated_at' => '2026-09-19T06:20:00Z',
            'site_domain' => 'example.com',
            'items' => [
                ['slug' => 'sample-plugin', 'name' => 'Sample Plugin'],
            ],
        ]);

        // On a different page like dashboard (index.php) -> no notice
        $GLOBALS['pagenow'] = 'index.php';
        ob_start();
        UpdateCoveragePage::maybeRenderPluginsNotice();
        $output = ob_get_clean();
        $this->assertEmpty($output);

        // On plugins.php -> notice renders
        $GLOBALS['pagenow'] = 'plugins.php';
        ob_start();
        UpdateCoveragePage::maybeRenderPluginsNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString('clockwork-update-exceptions-notice', $output);
        $this->assertStringContainsString('has paused automatic updates for 1 plugin(s) after repeated failures.', $output);
        $this->assertStringContainsString('View details', $output);

        // Theme-only pauses also surface on themes.php with theme copy.
        update_option(UpdateCoveragePage::OPTION, [
            'generated_at' => '2026-09-19T06:20:00Z',
            'site_domain' => 'example.com',
            'items' => [
                ['kind' => 'theme', 'slug' => 'sample-theme', 'name' => 'Sample Theme'],
            ],
        ]);

        $GLOBALS['pagenow'] = 'themes.php';
        ob_start();
        UpdateCoveragePage::maybeRenderPluginsNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString('has paused automatic updates for 1 theme(s) after repeated failures.', $output);
    }

    public function testPluginsNoticeDoesNotRenderWhenItemsEmpty(): void
    {
        update_option(UpdateCoveragePage::OPTION, [
            'items' => [],
        ]);

        $GLOBALS['pagenow'] = 'plugins.php';
        ob_start();
        UpdateCoveragePage::maybeRenderPluginsNotice();
        $output = ob_get_clean();
        $this->assertEmpty($output);
    }
}
