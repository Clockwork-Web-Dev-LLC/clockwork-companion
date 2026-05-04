<?php

namespace ClockworkCompanion\Auth;

use ClockworkCompanion\AuthAudit\Repository as AuditRepository;
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
 *   - 'rate_limited'      (429) — too many failed verifications from this IP
 *
 * Rate limit: 256-bit HMAC is uncrackable, so the threat we're defending
 * against isn't brute force — it's noise. A flood of bad-signature requests
 * is otherwise free DB writes (transient counters) and free CPU. Cap each
 * IP at RATE_MAX failures per RATE_WINDOW seconds, return 429 above that.
 */
class HmacVerifier
{
    private const REPLAY_WINDOW_SECONDS = 300;

    private const RATE_MAX = 30;

    private const RATE_WINDOW = 60;

    public static function verify(WP_REST_Request $request): true|\WP_Error
    {
        $ip = self::clientIp();

        if (self::isRateLimited($ip)) {
            // Record the rate-limit hit too — operators want to see HOW MUCH
            // probing was attempted, not just "the threshold was crossed once".
            self::noteFailure($ip, 'rate_limited', $request);

            return new \WP_Error('rate_limited', 'Too many failed verifications', ['status' => 429]);
        }

        $signature = $request->get_header('x_clockwork_signature');
        $timestamp = $request->get_header('x_clockwork_timestamp');

        if (! $signature || ! $timestamp) {
            self::noteFailure($ip, 'missing_headers', $request);

            return new \WP_Error('missing_headers', 'Missing X-Clockwork-Signature or X-Clockwork-Timestamp', ['status' => 401]);
        }

        $tsInt = (int) $timestamp;
        if (abs(time() - $tsInt) > self::REPLAY_WINDOW_SECONDS) {
            self::noteFailure($ip, 'stale_timestamp', $request);

            return new \WP_Error('stale_timestamp', 'Timestamp outside replay window', ['status' => 401]);
        }

        $secret = Secret::get();
        if ($secret === '') {
            return new \WP_Error('no_secret', 'Companion secret not initialised', ['status' => 500]);
        }

        $payload = strtoupper($request->get_method())
            ."\n".'/wp-json'.$request->get_route()
            ."\n".$tsInt
            ."\n".$request->get_body();

        $expected = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expected, $signature)) {
            self::noteFailure($ip, 'invalid_signature', $request);

            return new \WP_Error('invalid_signature', 'Signature mismatch', ['status' => 401]);
        }

        return true;
    }

    private static function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return $ip !== '' ? $ip : 'unknown';
    }

    private static function transientKey(string $ip): string
    {
        // Hash to keep the key length bounded + avoid leaking IPs into option names.
        return 'clockwork_hmac_fail_'.substr(hash('sha256', $ip), 0, 16);
    }

    private static function isRateLimited(string $ip): bool
    {
        $count = (int) get_transient(self::transientKey($ip));

        return $count >= self::RATE_MAX;
    }

    private static function noteFailure(string $ip, string $reason, WP_REST_Request $request): void
    {
        $key = self::transientKey($ip);
        $count = (int) get_transient($key);
        // Refresh the TTL on every failure — a steady stream stays locked out.
        set_transient($key, $count + 1, self::RATE_WINDOW);

        // Persist to the audit table for operator visibility. Best-effort:
        // wrapped so a DB issue inside the failure path can't itself become
        // a failure path. We never want logging to break auth.
        try {
            AuditRepository::insert([
                'ip' => $ip,
                'reason' => $reason,
                'request_path' => '/wp-json'.$request->get_route(),
                'request_method' => strtoupper($request->get_method()),
                'failed_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Swallow — auth path must not break on logging failures.
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[clockwork-auth-audit] insert failed: '.$e->getMessage());
            }
        }
    }
}
