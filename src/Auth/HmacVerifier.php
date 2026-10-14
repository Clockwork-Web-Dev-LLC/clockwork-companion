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
 * Query string: get_route() excludes it, so the payload above doesn't
 * cover it. Any request carrying query params (other than `rest_route`,
 * which the ?rest_route= fallback URL form adds) must also send
 * X-Clockwork-Signature-V2, signed over the same payload with the path
 * extended to REQUEST_PATH + "?" + canonicalQuery() — params sorted by
 * key, RFC 3986 encoded. Without that, a captured signed request could be
 * resent with altered or extra query params. Requests without query params
 * keep verifying against X-Clockwork-Signature alone, so Clockwork can send
 * both headers and stay compatible with older Companion versions.
 *
 * Replay window: 5 minutes. Requests outside the window are rejected even
 * if the signature is valid. Successful mutating requests (POST/PUT/PATCH/
 * DELETE) are also consumed for that window so a captured signed body
 * cannot be replayed. GETs are left idempotent.
 *
 * Result codes — return one of these to keep the WP_Error response shape
 * stable for Clockwork to switch on:
 *   - 'missing_headers'   (401)
 *   - 'stale_timestamp'   (401)
 *   - 'no_secret'         (500) — plugin loaded without a secret; should not happen
 *   - 'invalid_signature' (401)
 *   - 'replayed_request'  (401) — mutating request already consumed
 *   - 'rate_limited'      (429) — too many failed verifications from this IP
 *
 * Rate limit: 256-bit HMAC is uncrackable, so the threat we're defending
 * against isn't brute force — it's noise. A flood of bad-signature requests
 * is otherwise free DB writes (transient counters) and free CPU. Cap each
 * IP at RATE_MAX failures per RATE_WINDOW seconds, return 429 above that.
 *
 * Behind a CDN (Cloudflare etc.), `REMOTE_ADDR` is the edge IP — every
 * attacker shares one bucket and one noisy probe locks out the world.
 * Define `CLOCKWORK_COMPANION_TRUST_PROXY = true` in wp-config.php to read
 * the real client IP from `CF-Connecting-IP` / `X-Forwarded-For` instead.
 * Opt-in because trusting those headers without a real proxy in front lets
 * an attacker spoof their bucket key.
 */
class HmacVerifier
{
    private const REPLAY_WINDOW_SECONDS = 300;

    private const RATE_MAX = 30;

    private const RATE_WINDOW = 60;

    public static function verify(WP_REST_Request $request): bool|\WP_Error
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

        $path = '/wp-json'.$request->get_route();
        $query = self::canonicalQuery($request);
        if ($query !== '') {
            $signature = $request->get_header('x_clockwork_signature_v2');
            if (! $signature) {
                self::noteFailure($ip, 'missing_headers', $request);

                return new \WP_Error('missing_headers', 'Requests with query parameters must send X-Clockwork-Signature-V2', ['status' => 401]);
            }
            $path .= '?'.$query;
        }

        $payload = strtoupper($request->get_method())
            ."\n".$path
            ."\n".$tsInt
            ."\n".$request->get_body();

        $expected = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expected, $signature)) {
            self::noteFailure($ip, 'invalid_signature', $request);

            return new \WP_Error('invalid_signature', 'Signature mismatch', ['status' => 401]);
        }

        // Mutating methods only: consume this exact signed payload so a
        // captured POST/DELETE cannot be replayed inside the 5-minute window.
        // GETs stay idempotent — Clockwork polls /health and /snapshot with
        // empty bodies, and two calls in the same second would otherwise
        // collide on an identical signature.
        if (self::isMutating($request) && self::alreadyConsumed($expected, $tsInt)) {
            self::noteFailure($ip, 'replayed_request', $request);

            return new \WP_Error('replayed_request', 'Request already consumed', ['status' => 401]);
        }

        if (function_exists('update_option')) {
            update_option('clockwork_companion_last_contact_at', time(), false);
        }

        return true;
    }

    private static function isMutating(WP_REST_Request $request): bool
    {
        return in_array(strtoupper($request->get_method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Query params as signed by Clockwork: `rest_route` dropped, sorted by
     * key, RFC 3986 encoded. Empty string when there are none.
     */
    public static function canonicalQuery(WP_REST_Request $request): string
    {
        $params = (array) $request->get_query_params();
        unset($params['rest_route']);
        if ($params === []) {
            return '';
        }
        ksort($params, SORT_STRING);

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Marks the signature as used and reports whether it already was. A
     * single INSERT IGNORE against the unique option_name key makes the
     * check-and-mark atomic, so two copies of one signed request arriving
     * together can't both pass (a get_transient()/set_transient() pair
     * could). Expired markers are purged on the way in; that purge is ours
     * because transient garbage collection skips the options table on sites
     * with a persistent object cache.
     */
    private static function alreadyConsumed(string $signature, int $timestamp): bool
    {
        global $wpdb;

        $key = 'clockwork_hmac_used_'.substr(hash('sha256', $signature."\n".$timestamp), 0, 32);

        if (! isset($wpdb) || ! is_object($wpdb)) {
            return self::alreadyConsumedViaTransient($key);
        }

        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
            $wpdb->esc_like('clockwork_hmac_used_').'%',
            $now
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $key,
            (string) ($now + self::REPLAY_WINDOW_SECONDS)
        ));

        if ($inserted === false) {
            // DB error — don't let a broken options table lock Clockwork
            // out; fall back to the non-atomic check.
            return self::alreadyConsumedViaTransient($key);
        }

        return (int) $inserted === 0;
    }

    private static function alreadyConsumedViaTransient(string $key): bool
    {
        if (get_transient($key)) {
            return true;
        }

        set_transient($key, 1, self::REPLAY_WINDOW_SECONDS);

        return false;
    }

    private static function clientIp(): string
    {
        $candidate = '';

        if (defined('CLOCKWORK_COMPANION_TRUST_PROXY') && constant('CLOCKWORK_COMPANION_TRUST_PROXY')) {
            // Cloudflare puts the real client IP here directly; honoured first
            // because it's a single-value header (no chain to parse).
            if (! empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $candidate = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
            } elseif (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                // Generic XFF: leftmost entry is the original client per
                // RFC convention. Operator opted in via the constant above.
                $candidate = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            }
        }

        if ($candidate === '' && isset($_SERVER['REMOTE_ADDR'])) {
            $candidate = (string) $_SERVER['REMOTE_ADDR'];
        }

        // Validate so the audit table can't be poisoned by a garbage string
        // if any upstream ever populates these from untrusted input.
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }

        return 'unknown';
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
