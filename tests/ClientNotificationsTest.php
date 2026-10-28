<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Pages\NotificationsPage;
use ClockworkCompanion\Notifications\ClientNotifications;
use ClockworkCompanion\Plugin;
use ClockworkCompanion\Rest\ClientNotificationsRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class ClientNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_current_user'] = (object) ['ID' => 1, 'user_email' => 'admin@example.com'];
        $_GET = [];
        $_POST = [];
    }

    public function testCapabilityIsAdvertised(): void
    {
        $this->assertContains('client-notifications', Plugin::CAPABILITIES);
    }

    public function testSanitizeRecipientsFiltersInvalidAndDeduplicates(): void
    {
        $clean = ClientNotifications::sanitizeRecipients("Alice@Example.com, bob@example.com\nalice@example.com; not-an-email");
        $this->assertSame(['alice@example.com', 'bob@example.com'], $clean);
    }

    public function testSaveAndPayloadTrackActorAndTimestamps(): void
    {
        ClientNotifications::save([
            'slack_webhook_url' => 'https://hooks.slack.com/services/T1/B1/X1',
            'report_recipients' => ['owner@example.com', 'team@example.com'],
            'report_frequency' => 'weekly',
            'report_enabled' => true,
        ], 'wp_admin');

        $payload = ClientNotifications::payload();
        $this->assertSame('https://hooks.slack.com/services/T1/B1/X1', $payload['slack_webhook_url']);
        $this->assertSame(['owner@example.com', 'team@example.com'], $payload['report_recipients']);
        $this->assertSame('weekly', $payload['report_frequency']);
        $this->assertTrue($payload['report_enabled']);
        $this->assertSame('wp_admin', $payload['report_recipients_updated_by']);
        $this->assertNotEmpty($payload['report_recipients_updated_at']);
    }

    public function testRouteHandlesGetAndPostFromControl(): void
    {
        $route = new ClientNotificationsRoute();

        $postReq = new WP_REST_Request('POST', '/clockwork/v1/client-notifications', [
            'report_recipients' => ['client@agency.test'],
            'report_frequency' => 'monthly',
            'report_enabled' => true,
            'report_last_sent_at' => '2026-10-01T06:00:00+00:00',
        ]);
        $postRes = $route->handlePost($postReq);

        $this->assertSame(200, $postRes->get_status());
        $data = $postRes->get_data();
        $this->assertTrue($data['ok']);
        $this->assertSame(['client@agency.test'], $data['client_notifications']['report_recipients']);
        $this->assertSame('control', $data['client_notifications']['report_recipients_updated_by']);
        $this->assertSame('2026-10-01T06:00:00+00:00', $data['client_notifications']['report_last_sent_at']);

        $getRes = $route->handleGet();
        $this->assertSame(200, $getRes->get_status());
        $this->assertSame(['client@agency.test'], $getRes->get_data()['client_notifications']['report_recipients']);
    }

    public function testNotificationsPageRendersReportRecipientsCard(): void
    {
        ClientNotifications::save([
            'report_recipients' => ['billing@example.com'],
            'report_frequency' => 'monthly',
            'report_enabled' => true,
        ], 'control');

        ob_start();
        NotificationsPage::renderBody();
        $html = ob_get_clean();

        $this->assertStringContainsString('Care Plan &amp; Maintenance Reports', $html);
        $this->assertStringContainsString('billing@example.com', $html);
        $this->assertStringContainsString('Active · Monthly', $html);
    }
}
