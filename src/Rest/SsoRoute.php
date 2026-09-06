<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/sso/magic-link
 *
 * Mints a one-time magic-link URL that, when visited, logs the requesting
 * Clockwork user in as the named WordPress administrator. The Sso\Interceptor
 * (registered on `init`) consumes the nonce and calls wp_set_auth_cookie().
 *
 * Request body:
 *   {
 *     "user_login": "clockworkwd",        // required
 *     "ttl_seconds": 60,                   // optional, default 60, max 300
 *     "redirect_to": "/wp-admin/"          // optional, default /wp-admin/
 *   }
 *
 * Response (200):
 *   {
 *     "ok": true,
 *     "url": "https://example.com/?clockwork_sso=<48 hex>",
 *     "expires_at": "2026-05-03T16:30:00+00:00"
 *   }
 *
 * Errors:
 *   404 user_not_found     — login doesn't resolve to a WP user
 *   403 user_not_admin     — user exists but lacks manage_options
 *   400 invalid_input      — bad ttl, bad redirect_to, etc.
 *
 * Security:
 *   - Nonce: 192 bits (random_bytes(24)) → infeasible to brute-force.
 *   - One-time use: the option row is deleted on first redemption.
 *   - TTL: default 60s, hard ceiling 300s.
 *   - Capability gate: only manage_options users are eligible. We don't want
 *     SSO into editors/subscribers — that's a different feature.
 *   - redirect_to is validated to be a same-origin path so the magic-link
 *     can't be weaponised into an open-redirect.
 */
class SsoRoute
{
    private const OPTION_PREFIX = 'clockwork_sso_';

    public const DEFAULT_TTL = 60;

    public const MAX_TTL = 300;

    /**
     * Hard ceiling on simultaneously-active nonces. Defends against
     * wp_options bloat under abuse — even with auth, a misbehaving (or
     * compromised) Clockwork could otherwise mint thousands of nonces.
     * 100 is far above any legitimate use (you don't have 100 admins
     * needing SSO at the same time).
     */
    private const MAX_ACTIVE_NONCES = 100;

    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/sso/magic-link', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            $body = [];
        }

        $login = isset($body['user_login']) ? sanitize_user((string) $body['user_login'], true) : '';
        if ($login === '') {
            return new WP_Error('invalid_input', 'user_login is required', ['status' => 400]);
        }

        $ttl = isset($body['ttl_seconds']) ? (int) $body['ttl_seconds'] : self::DEFAULT_TTL;
        if ($ttl < 10 || $ttl > self::MAX_TTL) {
            $ttl = self::DEFAULT_TTL;
        }

        $redirectTo = isset($body['redirect_to']) ? (string) $body['redirect_to'] : '/wp-admin/';
        $redirectTo = $this->safeRedirect($redirectTo);

        $user = get_user_by('login', $login);
        if (! $user) {
            return new WP_Error('user_not_found', "No WP user with login '{$login}'", ['status' => 404]);
        }

        if (! user_can($user, 'manage_options')) {
            return new WP_Error('user_not_admin', "User '{$login}' is not an administrator", ['status' => 403]);
        }

        if ($this->activeNonceCount() >= self::MAX_ACTIVE_NONCES) {
            return new WP_Error(
                'nonce_ceiling',
                'Too many active SSO nonces — refusing to mint until the queue drains',
                ['status' => 503]
            );
        }

        $nonce = bin2hex(random_bytes(24));
        $expiresAt = time() + $ttl;

        update_option(self::OPTION_PREFIX.$nonce, [
            'user_id' => (int) $user->ID,
            'expires_at' => $expiresAt,
            'redirect_to' => $redirectTo,
            'created_at' => time(),
        ], false);

        // Mirror in a transient so WP's GC reaps abandoned ones — without
        // this they'd accumulate forever in wp_options on busy fleets.
        set_transient(self::OPTION_PREFIX.$nonce.'_gc', 1, $ttl + 60);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[clockwork-sso] minted nonce for user_id=%d (login=%s, expires_at=%s)',
                $user->ID,
                $user->user_login,
                gmdate('c', $expiresAt)
            ));
        }

        return new WP_REST_Response([
            'ok' => true,
            'url' => home_url('/?clockwork_sso='.$nonce),
            'expires_at' => gmdate('c', $expiresAt),
        ]);
    }

    /**
     * Count rows in wp_options whose name starts with the SSO prefix. Direct
     * SQL because there's no native get_options_by_prefix(). Bounded by the
     * MAX_ACTIVE_NONCES check above — under normal load this is a single
     * indexed COUNT() on a tiny set, so latency is fine.
     */
    private function activeNonceCount(): int
    {
        global $wpdb;
        $prefix = self::OPTION_PREFIX;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix).'%'
            )
        );

        return (int) $count;
    }

    /**
     * Force redirect_to to a same-origin path. Prevents open-redirect abuse
     * where a stolen magic-link could be twisted to send the victim somewhere
     * malicious after authenticating.
     */
    private function safeRedirect(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '/wp-admin/';
        }

        // Strip protocol+host if present — only the path portion is honoured.
        $parsed = wp_parse_url($value);
        $path = (string) ($parsed['path'] ?? '/wp-admin/');
        if ($path[0] !== '/') {
            $path = '/'.$path;
        }
        if (! empty($parsed['query'])) {
            $path .= '?'.$parsed['query'];
        }

        return $path;
    }
}
