<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Pages\ConnectionPage;
use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Auth\Secret;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_User;

class ConnectionPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_home_url'] = 'https://mysite.example.com';
        $GLOBALS['wp_test_current_user'] = null;
    }

    public function testConnectionKeyStructure(): void
    {
        $secret = Secret::ensure();
        $siteUrl = home_url();

        $connectionKey = base64_encode(wp_json_encode([
            'url' => $siteUrl,
            'secret' => $secret,
        ]));

        $decoded = json_decode(base64_decode($connectionKey), true);
        $this->assertIsArray($decoded);
        $this->assertSame('https://mysite.example.com', $decoded['url']);
        $this->assertSame($secret, $decoded['secret']);
        $this->assertSame(64, strlen($decoded['secret']));
    }

    public function testConnectionPageRendersCleanly(): void
    {
        ob_start();
        ConnectionPage::render();
        $output = ob_get_clean();

        $this->assertStringContainsString('Connection Key', $output);
        $this->assertStringContainsString('https://mysite.example.com', $output);
        $this->assertStringContainsString('Awaiting Connection', $output);
        $this->assertStringContainsString('cwkCopyConnectionKey', $output);
    }

    public function testHmacVerificationUpdatesLastContactOption(): void
    {
        $secret = Secret::ensure();
        $timestamp = time();
        $method = 'GET';
        $route = '/clockwork/v1/health';
        $body = '';

        $payload = "GET\n/wp-json/clockwork/v1/health\n{$timestamp}\n";
        $signature = hash_hmac('sha256', $payload, $secret);

        $request = new WP_REST_Request($method, $route, [], [
            'x-clockwork-timestamp' => (string) $timestamp,
            'x-clockwork-signature' => $signature,
        ], $body);

        $result = HmacVerifier::verify($request);
        $this->assertTrue($result);

        $lastContact = get_option('clockwork_companion_last_contact_at');
        $this->assertNotEmpty($lastContact);
        $this->assertGreaterThanOrEqual($timestamp - 1, $lastContact);
    }

    /**
     * The secret and the connection key used to be printed into the page for
     * every administrator (the key in an input, the secret inside an onclick
     * handler). They are now fetched on demand, by agency users only.
     */
    public function testConnectionPageKeepsCredentialsOutOfTheDom(): void
    {
        $secret = Secret::ensure();
        $key = base64_encode(wp_json_encode(['url' => home_url(), 'secret' => $secret]));

        ob_start();
        ConnectionPage::render();
        $output = ob_get_clean();

        $this->assertStringNotContainsString($secret, $output);
        $this->assertStringNotContainsString($key, $output);
        $this->assertStringContainsString('••••••••••••••••••••••••••••••••', $output);
    }

    public function testConnectionPageRestrictedForNonAgencyAdmin(): void
    {
        update_option('clockwork_companion_branding', ['agency_email_domains' => '@agency.example']);
        $GLOBALS['wp_test_current_user'] = new WP_User('admin@client.example');

        ob_start();
        ConnectionPage::render();
        $output = ob_get_clean();

        $this->assertStringContainsString('Connection Credentials Restricted', $output);
        $this->assertStringNotContainsString('cwkCopyConnectionKey', $output);
    }

    public function testRevealIsRefusedForNonAgencyAdmin(): void
    {
        update_option('clockwork_companion_branding', ['agency_email_domains' => '@agency.example']);
        $GLOBALS['wp_test_current_user'] = new WP_User('admin@client.example');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_error [403]');

        ConnectionPage::ajaxRevealCredentials();
    }

    public function testRevealReturnsCompanionFormatKeyForAgency(): void
    {
        update_option('clockwork_companion_branding', ['agency_email_domains' => '@agency.example']);
        $GLOBALS['wp_test_current_user'] = new WP_User('tech@agency.example');

        ob_start();
        ConnectionPage::ajaxRevealCredentials();
        $data = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($data['success']);
        $this->assertSame(['url' => 'https://mysite.example.com', 'secret' => Secret::get()], json_decode(base64_decode($data['data']['connection_key']), true));
        $this->assertSame(Secret::get(), $data['data']['secret']);
    }

    public function testRevealIsRegisteredAsAnAjaxAction(): void
    {
        $GLOBALS['wp_test_actions']['wp_ajax_'.ConnectionPage::AJAX_ACTION] = [];

        (new \ClockworkCompanion\Admin\Menu())->register();

        $this->assertSame([[ConnectionPage::class, 'ajaxRevealCredentials']], $GLOBALS['wp_test_actions']['wp_ajax_'.ConnectionPage::AJAX_ACTION]);
    }
}
