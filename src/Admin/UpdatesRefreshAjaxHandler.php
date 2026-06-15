<?php

namespace ClockworkCompanion\Admin;

use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\Updates\TransientRefresher;

/**
 * Internal admin-ajax handler that runs `wp_update_plugins()` and
 * `wp_update_themes()` inside admin-ajax.php — the request lifecycle where
 * WordPress sets `WP_ADMIN` + `DOING_AJAX` and premium-plugin update filters
 * (Freemius/Crocoblock/Elementor Pro/WPMU DEV) actually fire.
 *
 * Called via wp_remote_post() loopback from `TransientRefresher::refresh()`.
 * Auth: HMAC over `<action>|<timestamp>` using the Companion secret. The
 * signing string is distinct from the REST endpoints' HMAC (no body, no
 * path), so a captured signature can't be cross-replayed against /plugins
 * or /snapshot.
 *
 * Replay protection: 60-second timestamp window. Tight because the only
 * legitimate caller is the site talking to itself.
 *
 * Registered on `wp_ajax_nopriv_*` (no logged-in user required) since the
 * Companion REST request that triggers this has no user session. The HMAC
 * is the gate.
 */
class UpdatesRefreshAjaxHandler
{
    public const REPLAY_WINDOW_SEC = 60;

    public function register(): void
    {
        add_action('wp_ajax_'.TransientRefresher::AJAX_ACTION, [$this, 'handle']);
        add_action('wp_ajax_nopriv_'.TransientRefresher::AJAX_ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        $ts = isset($_POST['ts']) ? (int) $_POST['ts'] : 0;
        $sig = isset($_POST['sig']) ? (string) $_POST['sig'] : '';

        if ($ts === 0 || $sig === '') {
            wp_send_json_error(['error' => 'missing_signature'], 401);
        }

        if (abs(time() - $ts) > self::REPLAY_WINDOW_SEC) {
            wp_send_json_error(['error' => 'stale_timestamp'], 401);
        }

        $secret = Secret::get();
        if ($secret === '') {
            wp_send_json_error(['error' => 'no_secret_configured'], 500);
        }

        $expected = TransientRefresher::sign($ts, $secret);
        if (! hash_equals($expected, $sig)) {
            wp_send_json_error(['error' => 'bad_signature'], 401);
        }

        // We're now in real admin-ajax context. wp_update_plugins() /
        // wp_update_themes() will fire pre_set_site_transient_update_plugins
        // and pre_set_site_transient_update_themes filters; premium plugins
        // hook there and inject their licensed-update info.
        //
        // Pass a non-empty $extra_stats array to bypass WP's built-in 60s/12h
        // rate-limit short-circuit. Without it, when last_checked is recent
        // wp_update_plugins() returns immediately without firing the filter
        // chain — which is exactly the path that hid Crocoblock updates in
        // the first place (the WP cron already populated the transient hours
        // earlier, our call sees recent data, exits, premium filters never
        // run). The extra_stats payload is also sent to api.wordpress.org as
        // anonymous telemetry; tagging it identifies our calls in their logs.
        if (! function_exists('wp_update_plugins')) {
            require_once ABSPATH.'wp-includes/update.php';
        }
        $extra = ['source' => 'clockwork-companion-loopback'];
        wp_update_plugins($extra);
        wp_update_themes($extra);

        wp_send_json_success(['refreshed_at' => time()]);
    }
}
