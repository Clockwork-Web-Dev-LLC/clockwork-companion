<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Pages\ConnectionPage;
use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Auth\Secret;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class ConnectionPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_home_url'] = 'https://mysite.example.com';
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
}
