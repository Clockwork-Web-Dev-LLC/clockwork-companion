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

    public function testNonDigitTimestampIsRejected(): void
    {
        $ts = time();
        $request = $this->request('GET', '/clockwork/v1/health', [
            'x-clockwork-timestamp' => $ts.'abc',
            'x-clockwork-signature' => $this->sign('GET', '/wp-json/clockwork/v1/health', $ts, ''),
        ]);

        $result = HmacVerifier::verify($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('stale_timestamp', $result->get_error_code());
    }

    /**
     * Core's rest_send_allow_header() re-runs the permission callback on the
     * request it has just dispatched, once per method per handler.
     */
    public function testRepeatedPermissionCheckOnSameRequestIsNotTreatedAsReplay(): void
    {
        $ts = time();
        $body = '{"slug":"akismet"}';
        $request = $this->request('POST', '/clockwork/v1/plugins/update', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('POST', '/wp-json/clockwork/v1/plugins/update', $ts, $body),
        ], $body);

        $this->assertTrue(HmacVerifier::verify($request));
        $this->assertTrue(HmacVerifier::verify($request));
        $this->assertTrue(HmacVerifier::verify($request));

        $inserts = array_filter(
            $GLOBALS['wpdb']->queries,
            fn (string $q) => str_starts_with($q, 'INSERT IGNORE INTO wp_options')
        );
        $this->assertCount(1, $inserts, 'the replay store is only touched once per request');
        $this->assertSame([], $this->failureCounters(), 'no failure is recorded against the caller');
    }

    public function testRepeatedPermissionCheckReturnsTheSameFailureWithoutCountingItTwice(): void
    {
        $ts = time();
        $request = $this->request('POST', '/clockwork/v1/cache-flush', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => str_repeat('0', 64),
        ], '{}');

        $first = HmacVerifier::verify($request);
        $second = HmacVerifier::verify($request);

        $this->assertInstanceOf(WP_Error::class, $first);
        $this->assertSame($first, $second);
        $this->assertSame([1], array_values($this->failureCounters()));
    }

    public function testRequestAlteredAfterVerificationIsVerifiedAgain(): void
    {
        $ts = time();
        $body = '{"slug":"akismet"}';
        $request = $this->request('POST', '/clockwork/v1/plugins/update', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('POST', '/wp-json/clockwork/v1/plugins/update', $ts, $body),
        ], $body);

        $this->assertTrue(HmacVerifier::verify($request));

        $request->set_body('{"slug":"something-else"}');
        $result = HmacVerifier::verify($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_signature', $result->get_error_code());
    }

    public function testValidSignatureIsServedEvenWhenTheCallersIpIsRateLimited(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        for ($i = 0; $i < 40; $i++) {
            HmacVerifier::verify($this->request('GET', '/clockwork/v1/health', []));
        }

        $ts = time();
        $request = $this->request('GET', '/clockwork/v1/health', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('GET', '/wp-json/clockwork/v1/health', $ts, ''),
        ]);

        $this->assertTrue(HmacVerifier::verify($request));
    }

    public function testRateLimitedIpGets429AndNothingMoreIsWritten(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.11';
        $audit = $this->recordingWpdb();

        for ($i = 0; $i < 30; $i++) {
            $result = HmacVerifier::verify($this->request('GET', '/clockwork/v1/health', []));
            $this->assertSame('missing_headers', $result->get_error_code());
        }
        // 30 failures plus one marker row when the limit engaged.
        $this->assertCount(31, $audit->inserted);
        $this->assertSame('rate_limited', end($audit->inserted)['reason']);

        $countersBefore = $this->failureCounters();

        for ($i = 0; $i < 10; $i++) {
            $result = HmacVerifier::verify($this->request('GET', '/clockwork/v1/health', []));
            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('rate_limited', $result->get_error_code());
            $this->assertSame(429, $result->get_error_data()['status']);
        }

        $this->assertCount(31, $audit->inserted, 'refused requests are not audited');
        $this->assertSame($countersBefore, $this->failureCounters(), 'counter is not touched');
    }

    public function testForwardedForUsesTheEntryAppendedByTheTrustedProxy(): void
    {
        if (! defined('CLOCKWORK_COMPANION_TRUST_PROXY')) {
            define('CLOCKWORK_COMPANION_TRUST_PROXY', true);
        }
        // With no Trusted Proxies list, the constant trusts Cloudflare and
        // private peers; the load balancer here is on a private address.
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99, 192.0.2.20';
        $audit = $this->recordingWpdb();

        try {
            HmacVerifier::verify($this->request('GET', '/clockwork/v1/health', []));
        } finally {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        $this->assertSame('192.0.2.20', $audit->inserted[0]['ip']);
    }

    /**
     * CLOCKWORK_COMPANION_TRUST_PROXY used to make the headers authoritative
     * from any peer, so a caller could pick its own rate-limit bucket (and
     * the address recorded in the audit table) with one header.
     */
    public function testForwardingHeadersFromAnUntrustedPeerDoNotChooseTheBucket(): void
    {
        if (! defined('CLOCKWORK_COMPANION_TRUST_PROXY')) {
            define('CLOCKWORK_COMPANION_TRUST_PROXY', true);
        }
        $previousPeer = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.6';

        try {
            $this->assertSame('198.51.100.1', HmacVerifier::clientIp());

            // Rotating the headers does not escape the limit.
            for ($i = 0; $i < 30; $i++) {
                $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.' . ($i + 10);
                HmacVerifier::verify($this->request('GET', '/clockwork/v1/health', []));
            }
            $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.200';
            $result = HmacVerifier::verify($this->request('GET', '/clockwork/v1/health', []));
            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('rate_limited', $result->get_error_code());

            // A peer named in Gatekeeper's Trusted Proxies may relay the client.
            update_option(\ClockworkCompanion\Gatekeeper\Settings::OPTION, ['trusted_proxies' => ['198.51.100.0/24']]);
            $this->assertSame('203.0.113.200', HmacVerifier::clientIp());
        } finally {
            unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
            if ($previousPeer === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousPeer;
            }
        }
    }

    private function recordingWpdb(): wpdb
    {
        $GLOBALS['wpdb'] = new class extends wpdb {
            /** @var array<int, array<string, mixed>> */
            public array $inserted = [];

            public function insert(string $table, array $data, array|string $format = []): int|false
            {
                $this->inserted[] = $data;

                return 1;
            }
        };

        return $GLOBALS['wpdb'];
    }

    public function testReplayMarkerLastsAsLongAsAFutureStampedSignature(): void
    {
        $ts = time() + 240; // caller's clock four minutes ahead: still accepted
        $body = '{"slug":"akismet"}';
        $request = $this->request('POST', '/clockwork/v1/plugins/update', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('POST', '/wp-json/clockwork/v1/plugins/update', $ts, $body),
        ], $body);

        $this->assertTrue(HmacVerifier::verify($request));

        $insert = array_values(array_filter(
            $GLOBALS['wpdb']->queries,
            fn (string $q) => str_starts_with($q, 'INSERT IGNORE INTO wp_options')
        ))[0];
        preg_match("/VALUES \('[^']+', '(\d+)'/", $insert, $m);
        $this->assertGreaterThanOrEqual($ts + 300, (int) $m[1], 'marker outlives the signature');
    }

    public function testEmptyBodyWithFormFieldsIsRejected(): void
    {
        $ts = time();
        $request = $this->request('POST', '/clockwork/v1/plugins/update', [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $this->sign('POST', '/wp-json/clockwork/v1/plugins/update', $ts, ''),
            'content-type' => 'multipart/form-data; boundary=x',
        ]);

        $result = HmacVerifier::verify($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('unsigned_body', $result->get_error_code());

        $_POST = ['slug' => 'evil'];
        try {
            $request = $this->request('POST', '/clockwork/v1/plugins/update', [
                'x-clockwork-timestamp' => (string) $ts,
                'x-clockwork-signature' => $this->sign('POST', '/wp-json/clockwork/v1/plugins/update', $ts, ''),
            ]);
            $result = HmacVerifier::verify($request);
        } finally {
            $_POST = [];
        }
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('unsigned_body', $result->get_error_code());
    }

    /**
     * @return array<string, int> per-IP failure counters currently stored
     */
    private function failureCounters(): array
    {
        return array_filter(
            $GLOBALS['wp_test_transients'] ?? [],
            fn (string $key) => str_starts_with($key, 'clockwork_hmac_fail_'),
            ARRAY_FILTER_USE_KEY
        );
    }
}
