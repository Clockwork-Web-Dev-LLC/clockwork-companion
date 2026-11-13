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
 * is otherwise free DB writes (transient counters, audit rows). Each IP may
 * record RATE_MAX failures per RATE_WINDOW seconds; after that its failing
 * requests get a 429 and nothing more is written until the window lapses.
 * The limit never applies to a request whose signature verifies.
 *
 * Behind a CDN (Cloudflare etc.), `REMOTE_ADDR` is the edge IP — every
 * attacker shares one bucket and one noisy probe locks out the world.
 * The bucket key is the client address as Gatekeeper resolves it, so the
 * same trusted-proxy rules apply: `CF-Connecting-IP` / `X-Forwarded-For`
 * are read only from a peer listed in Gatekeeper's Trusted Proxies setting
 * or the `CLOCKWORK_COMPANION_TRUSTED_PROXIES` constant. With neither set,
 * `CLOCKWORK_COMPANION_TRUST_PROXY = true` trusts Cloudflare's ranges and
 * private networks. Trusting the headers from any other peer would let an
 * attacker spoof their bucket key.
 */
class HmacVerifier
{
    private const REPLAY_WINDOW_SECONDS = 300;

    private const RATE_MAX = 30;

    private const RATE_WINDOW = 60;

    /**
     * Outcome of verifying each request object already seen in this PHP
     * process, with a fingerprint of everything the signature covers.
     *
     * @var \WeakMap<WP_REST_Request, array{fingerprint: string, result: bool|\WP_Error}>|null
     */
    private static ?\WeakMap $verified = null;

    /**
     * WordPress calls a route's permission_callback more than once for the
     * same request: once to authorise the dispatch, then again from
     * rest_send_allow_header() (on rest_post_dispatch) for every method of
     * every handler registered on the route, to build the Allow header. For
     * a mutating request the first call has already consumed the signature,
     * so re-running the checks would report our own request as a replay and
     * count it as a failure against the caller's IP. The answer for one
     * request object cannot change while it is being served, so it is
     * computed once and handed back on the later calls.
     *
     * Keyed on the request object, not just on the signature: a second
     * request carrying the same signature is exactly the replay the marker
     * exists to refuse. The fingerprint guards against the object being
     * altered between calls.
     */
    public static function verify(WP_REST_Request $request): bool|\WP_Error
    {
        self::$verified ??= new \WeakMap();

        $fingerprint = self::fingerprint($request);
        $memo = self::$verified[$request] ?? null;
        if (is_array($memo) && hash_equals($memo['fingerprint'], $fingerprint)) {
            return $memo['result'];
        }

        $result = self::verifyOnce($request);
        self::$verified[$request] = ['fingerprint' => $fingerprint, 'result' => $result];

        return $result;
    }

    private static function fingerprint(WP_REST_Request $request): string
    {
        return hash('sha256', implode("\n", [
            strtoupper($request->get_method()),
            $request->get_route(),
            self::canonicalQuery($request),
            (string) $request->get_header('x_clockwork_timestamp'),
            (string) $request->get_header('x_clockwork_signature'),
            (string) $request->get_header('x_clockwork_signature_v2'),
            hash('sha256', $request->get_body()),
        ]));
    }

    private static function verifyOnce(WP_REST_Request $request): bool|\WP_Error
    {
        $ip = self::clientIp();

        // Read once, before anything is recorded. The limit only ever answers
        // requests that fail verification: a correctly signed request is
        // served however many bad ones share its IP, otherwise anyone able to
        // send (or spoof) unsigned requests from the caller's address could
        // lock the real caller out.
        $limited = self::isRateLimited($ip);

        $signature = $request->get_header('x_clockwork_signature');
        $timestamp = $request->get_header('x_clockwork_timestamp');

        if (! $signature || ! $timestamp) {
            return self::reject($ip, $limited, $request, 'missing_headers', 'Missing X-Clockwork-Signature or X-Clockwork-Timestamp');
        }

        if (! ctype_digit((string) $timestamp)) {
            return self::reject($ip, $limited, $request, 'stale_timestamp', 'Timestamp outside replay window');
        }

        $tsInt = (int) $timestamp;
        if (abs(time() - $tsInt) > self::REPLAY_WINDOW_SECONDS) {
            return self::reject($ip, $limited, $request, 'stale_timestamp', 'Timestamp outside replay window');
        }

        // The signature covers the raw body. For multipart/form-data PHP
        // leaves the raw body empty and puts the fields in $_POST/$_FILES,
        // where get_param() would read them unsigned — so a captured
        // empty-body signed POST could be resent with any form fields.
        // Clockwork only ever sends JSON; refuse anything else of that shape.
        if (self::hasUnsignedBodyParams($request)) {
            return self::reject($ip, $limited, $request, 'unsigned_body', 'Form or file fields are not covered by the signature; send a JSON body');
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
                return self::reject($ip, $limited, $request, 'missing_headers', 'Requests with query parameters must send X-Clockwork-Signature-V2');
            }
            $path .= '?'.$query;
        }

        $payload = strtoupper($request->get_method())
            ."\n".$path
            ."\n".$tsInt
            ."\n".$request->get_body();

        $expected = hash_hmac('sha256', $payload, $secret);
        $promote = '';

        if (! hash_equals($expected, $signature)) {
            // Mid-rotation, Clockwork may already be signing with the secret
            // it was just issued (see Secret). Accept it; it becomes current
            // once the request has passed every check below.
            $pending = Secret::pending();
            $pendingExpected = $pending !== '' ? hash_hmac('sha256', $payload, $pending) : '';
            if ($pendingExpected === '' || ! hash_equals($pendingExpected, $signature)) {
                return self::reject($ip, $limited, $request, 'invalid_signature', 'Signature mismatch');
            }
            $expected = $pendingExpected;
            $promote = $pending;
        }

        // Mutating methods only: consume this exact signed payload so a
        // captured POST/DELETE cannot be replayed inside the 5-minute window.
        // GETs stay idempotent — Clockwork polls /health and /snapshot with
        // empty bodies, and two calls in the same second would otherwise
        // collide on an identical signature.
        if (self::isMutating($request) && self::alreadyConsumed($expected, $tsInt)) {
            return self::reject($ip, $limited, $request, 'replayed_request', 'Request already consumed');
        }

        if ($promote !== '') {
            Secret::promote($promote);
        }

        // At most one write a minute: this runs for every signed request.
        if (function_exists('update_option') && time() - (int) get_option('clockwork_companion_last_contact_at', 0) >= 60) {
            update_option('clockwork_companion_last_contact_at', time(), false);
        }

        return true;
    }

    /**
     * Refuse a request that failed verification. Below the limit the failure
     * is counted and audited and the specific reason returned. Once the IP is
     * over the limit the answer is a bare 429 and nothing is written — a
     * flood must not be able to turn every request into a transient write
     * plus an audit row.
     */
    private static function reject(string $ip, bool $limited, WP_REST_Request $request, string $code, string $message): \WP_Error
    {
        if ($limited) {
            return new \WP_Error('rate_limited', 'Too many failed verifications', ['status' => 429]);
        }

        self::noteFailure($ip, $code, $request);

        return new \WP_Error($code, $message, ['status' => 401]);
    }

    private static function hasUnsignedBodyParams(WP_REST_Request $request): bool
    {
        if ($request->get_body() !== '') {
            return false;
        }

        $type = strtolower((string) $request->get_header('content_type'));
        if (str_starts_with($type, 'multipart/form-data')) {
            return true;
        }

        $bodyParams = method_exists($request, 'get_body_params') ? (array) $request->get_body_params() : [];
        $fileParams = method_exists($request, 'get_file_params') ? (array) $request->get_file_params() : [];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only checking for presence; HMAC is the auth.
        return $bodyParams !== [] || $fileParams !== [] || ! empty($_POST) || ! empty($_FILES);
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
            return self::alreadyConsumedViaTransient($key, max(time(), $timestamp) + self::REPLAY_WINDOW_SECONDS - time());
        }

        $now = time();
        // Keep the marker as long as the signature itself is acceptable:
        // verify() takes timestamps up to REPLAY_WINDOW_SECONDS in the
        // future, so a marker counted from receipt time could be purged
        // while a request stamped ahead of our clock still verifies.
        $expiresAt = max($now, $timestamp) + self::REPLAY_WINDOW_SECONDS;
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
            (string) $expiresAt
        ));

        if ($inserted === false) {
            // DB error — don't let a broken options table lock Clockwork
            // out; fall back to the non-atomic check.
            return self::alreadyConsumedViaTransient($key, $expiresAt - $now);
        }

        return (int) $inserted === 0;
    }

    private static function alreadyConsumedViaTransient(string $key, int $ttl): bool
    {
        if (get_transient($key)) {
            return true;
        }

        set_transient($key, 1, max(1, $ttl));

        return false;
    }

    /**
     * The caller's address, resolved by Gatekeeper's rules: forwarding
     * headers count only when the connecting peer is a trusted proxy (see
     * Gatekeeper\ClientIp). Trusting them from any peer whenever
     * CLOCKWORK_COMPANION_TRUST_PROXY was set let a caller pick its own
     * rate-limit bucket and the address written to the audit tables.
     */
    public static function clientIp(): string
    {
        return \ClockworkCompanion\Gatekeeper\ClientIp::get();
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
        $count = (int) get_transient($key) + 1;
        // Refresh the TTL on every counted failure. Failures stop being
        // counted once the limit is reached (see reject()), so the window
        // runs from the failure that reached it.
        set_transient($key, $count, self::RATE_WINDOW);

        self::audit($ip, $reason, $request);
        if ($count === self::RATE_MAX) {
            // One marker row so operators can see the limit engaged; the
            // requests refused after this point are deliberately not logged.
            self::audit($ip, 'rate_limited', $request);
        }
    }

    private static function audit(string $ip, string $reason, WP_REST_Request $request): void
    {
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
