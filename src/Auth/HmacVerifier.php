<?php

namespace ClockworkCompanion\Auth;

use WP_REST_Request;

/**
 * Verifies HMAC-SHA256 signatures on incoming Clockwork → Companion requests.
 *
 * Signature is over: METHOD + "\n" + REQUEST_PATH + "\n" + TIMESTAMP + "\n" + BODY
 * - REQUEST_PATH starts with /wp-json/...; we use $request->get_route()
 *   prefixed with /wp-json so Clockwork can construct the same string
 *   without knowing WP's mu-plugin internals.
 * - TIMESTAMP is X-Clockwork-Timestamp (unix seconds, integer).
 * - BODY is the raw request body (empty string for GET).
 *
 * Replay window: 5 minutes. Requests outside the window are rejected even
 * if the signature is valid. Defends against captured-request replay.
 *
 * Result codes — return one of these to keep the WP_Error response shape
 * stable for Clockwork to switch on:
 *   - 'missing_headers'   (401)
 *   - 'stale_timestamp'   (401)
 *   - 'no_secret'         (500) — plugin loaded without a secret; should not happen
 *   - 'invalid_signature' (401)
 */
class HmacVerifier
{
    private const REPLAY_WINDOW_SECONDS = 300;

    public static function verify(WP_REST_Request $request): true|\WP_Error
    {
        $signature = $request->get_header('x_clockwork_signature');
        $timestamp = $request->get_header('x_clockwork_timestamp');

        if (! $signature || ! $timestamp) {
            return new \WP_Error('missing_headers', 'Missing X-Clockwork-Signature or X-Clockwork-Timestamp', ['status' => 401]);
        }

        $tsInt = (int) $timestamp;
        if (abs(time() - $tsInt) > self::REPLAY_WINDOW_SECONDS) {
            return new \WP_Error('stale_timestamp', 'Timestamp outside replay window', ['status' => 401]);
        }

        $secret = Secret::get();
        if ($secret === '') {
            return new \WP_Error('no_secret', 'Companion secret not initialised', ['status' => 500]);
        }

        $payload = strtoupper($request->get_method())
            . "\n" . '/wp-json' . $request->get_route()
            . "\n" . $tsInt
            . "\n" . $request->get_body();

        $expected = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expected, $signature)) {
            return new \WP_Error('invalid_signature', 'Signature mismatch', ['status' => 401]);
        }

        return true;
    }
}
