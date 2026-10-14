<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Auth\Secret;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use wpdb;

class HmacVerifierTest extends TestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_transients'] = [];
        $GLOBALS['wpdb'] = new wpdb();
        $this->secret = Secret::ensure();
    }

    private function sign(string $method, string $path, int $ts, string $body): string
    {
        return hash_hmac('sha256', "{$method}\n{$path}\n{$ts}\n{$body}", $this->secret);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     */
    private function request(string $method, string $route, array $headers, string $body = '', array $query = []): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $route, [], $headers, $body);
        $request->set_query_params($query);

        return $request;
    }

    public function testRequestWithoutQueryVerifiesAgainstV1Signature(): void
    {
        $ts = time();
        $request = $this->request('GET', '/clockwork/v1/health', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('GET', '/wp-json/clockwork/v1/health', $ts, ''),
        ]);

        $this->assertTrue(HmacVerifier::verify($request));
    }

    public function testRestRouteFallbackParamDoesNotRequireV2(): void
    {
        $ts = time();
        $request = $this->request('GET', '/clockwork/v1/health', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('GET', '/wp-json/clockwork/v1/health', $ts, ''),
        ], '', ['rest_route' => '/clockwork/v1/health']);

        $this->assertTrue(HmacVerifier::verify($request));
    }

    public function testQueryParamsWithOnlyV1SignatureAreRejected(): void
    {
        $ts = time();
        $request = $this->request('GET', '/clockwork/v1/resource-report', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('GET', '/wp-json/clockwork/v1/resource-report', $ts, ''),
        ], '', ['limit' => '200']);

        $result = HmacVerifier::verify($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_headers', $result->get_error_code());
    }

    public function testV2SignatureCoversSortedQueryAndIgnoresRestRoute(): void
    {
        $ts = time();
        $path = '/wp-json/clockwork/v1/resource-report?limit=200&since=2026-10-01T00%3A00%3A00Z';
        $request = $this->request('GET', '/clockwork/v1/resource-report', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('GET', '/wp-json/clockwork/v1/resource-report', $ts, ''),
            'x-clockwork-signature-v2' => $this->sign('GET', $path, $ts, ''),
        ], '', [
            'since' => '2026-10-01T00:00:00Z',
            'rest_route' => '/clockwork/v1/resource-report',
            'limit' => '200',
        ]);

        $this->assertTrue(HmacVerifier::verify($request));
    }

    public function testTamperedQueryValueIsRejected(): void
    {
        $ts = time();
        $request = $this->request('GET', '/clockwork/v1/resource-report', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('GET', '/wp-json/clockwork/v1/resource-report', $ts, ''),
            'x-clockwork-signature-v2' => $this->sign('GET', '/wp-json/clockwork/v1/resource-report?limit=200', $ts, ''),
        ], '', ['limit' => '5000']);

        $result = HmacVerifier::verify($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_signature', $result->get_error_code());
    }

    public function testMutatingRequestCannotBeReplayed(): void
    {
        $ts = time();
        $body = '{"slug":"akismet"}';
        $headers = [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('POST', '/wp-json/clockwork/v1/plugins/update', $ts, $body),
        ];

        $this->assertTrue(HmacVerifier::verify($this->request('POST', '/clockwork/v1/plugins/update', $headers, $body)));

        $replay = HmacVerifier::verify($this->request('POST', '/clockwork/v1/plugins/update', $headers, $body));
        $this->assertInstanceOf(WP_Error::class, $replay);
        $this->assertSame('replayed_request', $replay->get_error_code());
    }

    public function testReplayCheckUsesSingleInsertIgnore(): void
    {
        $ts = time();
        $body = '{}';
        $request = $this->request('POST', '/clockwork/v1/cache-flush', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('POST', '/wp-json/clockwork/v1/cache-flush', $ts, $body),
        ], $body);

        $this->assertTrue(HmacVerifier::verify($request));

        $inserts = array_values(array_filter(
            $GLOBALS['wpdb']->queries,
            fn (string $q) => str_starts_with($q, 'INSERT IGNORE INTO wp_options')
        ));
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString("'clockwork_hmac_used_", $inserts[0]);
        // The atomic path, not the old transient pair.
        $this->assertSame([], $GLOBALS['wp_test_transients'] ?? []);
    }

    public function testGetRequestsAreNotConsumed(): void
    {
        $ts = time();
        $headers = [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('GET', '/wp-json/clockwork/v1/snapshot', $ts, ''),
        ];

        $this->assertTrue(HmacVerifier::verify($this->request('GET', '/clockwork/v1/snapshot', $headers)));
        $this->assertTrue(HmacVerifier::verify($this->request('GET', '/clockwork/v1/snapshot', $headers)));
    }
}
