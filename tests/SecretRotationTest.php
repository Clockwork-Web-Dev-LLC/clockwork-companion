<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\Rest\SecretRotateRoute;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use wpdb;

class SecretRotationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_transients'] = [];
        $GLOBALS['wp_test_update_option_fails'] = [];
        $GLOBALS['wpdb'] = new wpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_test_update_option_fails'] = [];
        parent::tearDown();
    }

    private function signedGet(string $secret): WP_REST_Request
    {
        $ts = time();
        $sig = hash_hmac('sha256', "GET\n/wp-json/clockwork/v1/health\n{$ts}\n", $secret);

        return new WP_REST_Request('GET', '/clockwork/v1/health', [], [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => $sig,
        ]);
    }

    public function testRotationKeepsTheOldSecretValidUntilTheNewOneIsUsed(): void
    {
        $old = Secret::ensure();
        $new = Secret::rotate();

        $this->assertNotSame($old, $new);
        $this->assertSame($old, Secret::get(), 'current secret is unchanged by rotate()');
        $this->assertSame($new, Secret::pending());

        // Lost response: Clockwork still signs with the old secret.
        $this->assertTrue(HmacVerifier::verify($this->signedGet($old)));
        $this->assertSame($new, Secret::pending());

        // Clockwork got the response and switched.
        $this->assertTrue(HmacVerifier::verify($this->signedGet($new)));
        $this->assertSame($new, Secret::get());
        $this->assertSame('', Secret::pending());

        $result = HmacVerifier::verify($this->signedGet($old));
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_signature', $result->get_error_code());
    }

    public function testNewRotateReplacesAnUnconfirmedPendingSecret(): void
    {
        $old = Secret::ensure();
        $lost = Secret::rotate();
        $retry = Secret::rotate();

        $this->assertSame($retry, Secret::pending());
        $this->assertInstanceOf(WP_Error::class, HmacVerifier::verify($this->signedGet($lost)));
        $this->assertTrue(HmacVerifier::verify($this->signedGet($old)));
        $this->assertTrue(HmacVerifier::verify($this->signedGet($retry)));
        $this->assertSame($retry, Secret::get());
    }

    public function testRotateRouteDoesNotReturnASecretThatWasNotStored(): void
    {
        $old = Secret::ensure();
        $GLOBALS['wp_test_update_option_fails'][Secret::PENDING_OPTION_KEY] = true;
        $GLOBALS['wp_test_update_option_fails'][Secret::OPTION_KEY] = true;

        $response = (new SecretRotateRoute())->handle(new WP_REST_Request('POST', '/clockwork/v1/secret/rotate'));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rotate_failed', $response->get_error_code());
        $this->assertSame($old, Secret::get());
    }

    public function testRotateRouteReturnsTheStoredPendingSecret(): void
    {
        Secret::ensure();
        $response = (new SecretRotateRoute())->handle(new WP_REST_Request('POST', '/clockwork/v1/secret/rotate'));

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(Secret::pending(), $response->get_data()['secret']);
    }

    /**
     * Runs last in this class on purpose: the constant cannot be undefined,
     * and an empty value is exactly what must behave like "not defined".
     */
    public function testEmptySecretConstantFallsBackToTheStoredSecret(): void
    {
        if (! defined('CLOCKWORK_COMPANION_SECRET')) {
            define('CLOCKWORK_COMPANION_SECRET', '');
        }

        $secret = Secret::ensure();
        $this->assertSame(64, strlen($secret));
        $this->assertTrue(Secret::isManaged());
        $this->assertSame($secret, Secret::get());
    }
}
