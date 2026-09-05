<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\Admin\Pages\TrafficPage;
use ClockworkCompanion\Rest\TrafficReportRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class TrafficSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
    }

    public function testTrafficPageIsSupportedReturnsFalseWhenNoOptionExists(): void
    {
        delete_option(TrafficPage::OPTION);
        $this->assertFalse(TrafficPage::isSupported());
        
        $summary = TrafficPage::summary();
        $this->assertFalse($summary['hasReport']);
        $this->assertNull($summary['visits30dLabel']);
    }

    public function testTrafficPageIsSupportedReturnsFalseWhenExplicitlyUnsupported(): void
    {
        update_option(TrafficPage::OPTION, [
            'supported' => false,
            'reason' => 'ssh_not_configured',
            'daily' => [],
        ]);

        $this->assertFalse(TrafficPage::isSupported());
    }

    public function testTrafficPageIsSupportedReturnsFalseWhenEnabledIsFalse(): void
    {
        update_option(TrafficPage::OPTION, [
            'enabled' => false,
            'daily' => [['date' => '2026-09-01', 'visits' => 50]],
        ]);

        $this->assertFalse(TrafficPage::isSupported());
    }

    public function testTrafficPageIsSupportedReturnsFalseWhenDailyAndPeriodSummaryAreEmpty(): void
    {
        update_option(TrafficPage::OPTION, [
            'supported' => true,
            'daily' => [],
            'period_summary' => null,
        ]);

        $this->assertFalse(TrafficPage::isSupported());
    }

    public function testTrafficPageIsSupportedReturnsTrueWithValidDailyRollup(): void
    {
        update_option(TrafficPage::OPTION, [
            'supported' => true,
            'daily' => [
                ['date' => '2026-09-01', 'visits' => 120, 'requests' => 300],
            ],
            'totals' => ['month_30d' => 120],
        ]);

        $this->assertTrue(TrafficPage::isSupported());

        $summary = TrafficPage::summary();
        $this->assertTrue($summary['hasReport']);
        $this->assertEquals('120', $summary['visits30dLabel']);
    }

    public function testTrafficPageIsSupportedReturnsTrueWithValidPeriodSummary(): void
    {
        update_option(TrafficPage::OPTION, [
            'supported' => true,
            'daily' => [],
            'period_summary' => [
                'today' => ['views' => 20, 'visitors' => 15],
            ],
        ]);

        $this->assertTrue(TrafficPage::isSupported());
    }

    public function testLayoutTabsOmitsTrafficWhenUnsupported(): void
    {
        delete_option(TrafficPage::OPTION);

        $tabs = Layout::tabs();
        $slugs = array_column($tabs, 'slug');

        $this->assertNotContains('traffic', $slugs);
    }

    public function testLayoutTabsIncludesTrafficWhenSupported(): void
    {
        update_option(TrafficPage::OPTION, [
            'daily' => [['date' => '2026-09-01', 'visits' => 10]],
        ]);

        $tabs = Layout::tabs();
        $slugs = array_column($tabs, 'slug');

        $this->assertContains('traffic', $slugs);
    }

    public function testTrafficReportRouteIngestsUnsupportedPayload(): void
    {
        $request = new WP_REST_Request(
            'POST',
            '/traffic-report',
            [
                'source' => 'clockwork-monitoring',
                'supported' => false,
                'reason' => 'ssh_not_configured',
            ]
        );

        $route = new TrafficReportRoute();
        $response = $route->handle($request);

        $this->assertEquals(200, $response->get_status());
        $this->assertTrue($response->get_data()['ok']);
        $this->assertFalse($response->get_data()['supported']);

        $stored = get_option(TrafficPage::OPTION);
        $this->assertIsArray($stored);
        $this->assertFalse($stored['supported']);
        $this->assertEquals('ssh_not_configured', $stored['reason']);
        $this->assertFalse(TrafficPage::isSupported());
    }

    public function testTrafficReportRouteIngestsValidReport(): void
    {
        $request = new WP_REST_Request(
            'POST',
            '/traffic-report',
            [
                'source' => 'clockwork-monitoring',
                'supported' => true,
                'daily' => [
                    ['date' => '2026-09-01', 'requests' => 100, 'visits' => 50],
                ],
                'totals' => ['today' => 50, 'month_30d' => 50],
                'has_data' => true,
            ]
        );

        $route = new TrafficReportRoute();
        $response = $route->handle($request);

        $this->assertEquals(200, $response->get_status());
        $this->assertTrue($response->get_data()['ok']);

        $stored = get_option(TrafficPage::OPTION);
        $this->assertIsArray($stored);
        $this->assertTrue($stored['supported']);
        $this->assertCount(1, $stored['daily']);
        $this->assertTrue(TrafficPage::isSupported());
    }
}