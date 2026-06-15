<?php

namespace ClockworkCompanion\Updates;

use ClockworkCompanion\Auth\Secret;

/**
 * Refreshes the WordPress `update_plugins` / `update_themes` site transients
 * by making a loopback HTTP request to `admin-ajax.php`. The ajax call runs
 * in real admin context (WordPress sets `WP_ADMIN` and `DOING_AJAX` for
 * admin-ajax.php), so premium-plugin update filters that gate on those —
 * Freemius-based plugins like Crocoblock Jet*, Elementor Pro, WPMU DEV
 * Hummingbird, etc. — fire and inject their licensed updates into the
 * transient. Without this, those updates are visible in wp-admin but NOT
 * to REST/CLI callers (including wp-cli's `plugin list --update=available`
 * and Companion's own `/plugins` route).
 *
 * Rate-limited: skips when `update_plugins.last_checked` is < 30 min old, so
 * back-to-back snapshot calls don't loop. Soft-fail: a wp_remote_post error
 * (loopback can't reach itself, ajax returns 5xx, etc.) is swallowed — the
 * caller continues with whatever the cron-driven transient already had,
 * which is strictly better than blocking the snapshot.
 */
class TransientRefresher
{
    public const MIN_REFRESH_INTERVAL_SEC = 30 * 60;

    public const AJAX_ACTION = 'clockwork_refresh_updates';

    /**
     * Build the HMAC signed by the loopback request, verified by the
     * receiving ajax handler. Distinct signing string from the REST HMAC so
     * a captured signature can't cross-replay against the REST endpoints.
     */
    public static function sign(int $ts, string $secret): string
    {
        return hash_hmac('sha256', self::AJAX_ACTION.'|'.$ts, $secret);
    }

    public static function refresh(): void
    {
        if (! self::shouldRefresh()) {
            return;
        }

        self::triggerLoopback();
    }

    private static function shouldRefresh(): bool
    {
        $transient = get_site_transient('update_plugins');
        if (! is_object($transient)) {
            return true;
        }
        $lastChecked = isset($transient->last_checked) && is_numeric($transient->last_checked)
            ? (int) $transient->last_checked
            : 0;
        if ($lastChecked <= 0) {
            return true;
        }

        return (time() - $lastChecked) >= self::MIN_REFRESH_INTERVAL_SEC;
    }

    private static function triggerLoopback(): void
    {
        $secret = Secret::get();
        if ($secret === '') {
            return;
        }

        $ts = time();
        $sig = self::sign($ts, $secret);
        $url = admin_url('admin-ajax.php');

        // wp_remote_post on the site's own admin-ajax. blocking=true because
        // we need the refresh to complete before the caller reads the transient.
        // sslverify=false because some sites front themselves with self-signed
        // or CF-edge certs that fail strict validation on loopback.
        wp_remote_post($url, [
            'timeout' => 30,
            'blocking' => true,
            'sslverify' => false,
            'body' => [
                'action' => self::AJAX_ACTION,
                'ts' => $ts,
                'sig' => $sig,
            ],
        ]);
        // Soft-fail: ignore errors. Caller falls back to whatever WP cron
        // last cached. Worst case we keep the existing behavior (premium
        // plugin updates invisible).
    }
}
