<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Updates\TransientRefresher;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/plugins
 *
 * Full installed-plugin inventory. Replaces the SSH+wp-cli probe in
 * App\Services\Sites\WpPluginDetector for sites that have Companion.
 *
 * Reads the update_plugins transient, but first asks TransientRefresher to
 * refresh it via a loopback admin-ajax call (rate-limited to once per 30
 * min). The loopback makes premium-plugin filters (Freemius/Crocoblock/
 * Elementor Pro/WPMU DEV) fire — they gate their update injection on
 * admin context which a plain REST request doesn't have. Without the
 * refresher, licensed plugins are invisible here even though they show
 * up in wp-admin's update screen.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "plugins": [
 *       {
 *         "slug": "akismet/akismet.php",
 *         "name": "Akismet Anti-Spam",
 *         "version": "5.3.7",
 *         "active": true,
 *         "update_available": true,
 *         "new_version": "5.4.0" | null,
 *         "auto_update": false
 *       },
 *       ...
 *     ],
 *     "counts": {
 *       "total": 42,
 *       "active": 18,
 *       "inactive": 24,
 *       "updates_available": 3
 *     },
 *     "checked_at": "2026-05-02T20:00:00+00:00" | null
 *   }
 */
class PluginsRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/plugins', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->payload());
    }

    /**
     * Public so SnapshotRoute can compose without re-issuing an HTTP call.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        // Refresh the transient via loopback admin-ajax so premium plugins
        // contribute their licensed-update entries. Rate-limited internally
        // (30 min) so SnapshotRoute calling both PluginsRoute and ThemesRoute
        // in the same request doesn't double-loopback.
        TransientRefresher::refresh();

        $all = get_plugins();
        $activePaths = (array) get_option('active_plugins', []);

        // Multisite: network-active plugins live in `wp_sitemeta.active_sitewide_plugins`
        // (a slug => activated_at map), NOT in the main blog's `active_plugins` option.
        // Without merging this, network-activated plugins (Beaver Builder on multisite
        // designs, etc.) show as `active=false` here — which (a) misleads operators
        // looking at the snapshot, and (b) means PostUpdateVerifyRoute never knows to
        // re-activate them when WordPress's filesystem-swap window deactivates them
        // during a plugin upgrade. Caught after a client site lost Beaver Builder
        // during an unrelated bb-theme-builder upgrade in June 2026.
        $networkActiveMap = function_exists('is_multisite') && is_multisite()
            ? (array) get_site_option('active_sitewide_plugins', [])
            : [];
        $networkActivePaths = array_keys($networkActiveMap);

        $updateTransient = get_site_transient('update_plugins');
        $autoUpdates = (array) get_site_option('auto_update_plugins', []);

        $updates = [];
        $checkedAt = null;
        if (is_object($updateTransient)) {
            if (isset($updateTransient->response) && is_array($updateTransient->response)) {
                $updates = $updateTransient->response;
            }
            if (isset($updateTransient->last_checked) && is_numeric($updateTransient->last_checked)) {
                $checkedAt = gmdate('c', (int) $updateTransient->last_checked);
            }
        }

        $rows = [];
        $activeCount = 0;
        $updateCount = 0;

        foreach ($all as $slug => $meta) {
            $isNetworkActive = in_array($slug, $networkActivePaths, true);
            $isSiteActive = in_array($slug, $activePaths, true);
            // A network-active plugin counts as active for the purposes of "is
            // this plugin actually running on the site?" — same effective state.
            $isActive = $isSiteActive || $isNetworkActive;

            $newVersion = isset($updates[$slug]->new_version)
                ? (string) $updates[$slug]->new_version
                : null;
            // Only flag as update_available when the new version is actually
            // newer than what's installed. Stale transients occasionally keep
            // a slug in response[] after the site was already updated to that
            // version, causing false "X → X" entries in the updates queue.
            $hasUpdate = $newVersion !== null
                && version_compare($newVersion, (string) ($meta['Version'] ?? ''), '>');

            if ($isActive) {
                $activeCount++;
            }
            if ($hasUpdate) {
                $updateCount++;
            }

            $rows[] = [
                'slug' => (string) $slug,
                'name' => (string) ($meta['Name'] ?? $slug),
                'version' => (string) ($meta['Version'] ?? ''),
                'active' => $isActive,
                // New field (Companion 1.22.2+): distinguishes per-site activation
                // from network-wide activation on multisite. PostUpdateVerifyRoute
                // uses this to know whether to call activate_plugin() per-site or
                // with $network_wide=true after an upgrade silently deactivates a
                // plugin. Always present (false on single-site installs).
                'network_active' => $isNetworkActive,
                'update_available' => $hasUpdate,
                'new_version' => $newVersion,
                'auto_update' => in_array($slug, $autoUpdates, true),
            ];
        }

        $total = count($rows);

        return [
            'ok' => true,
            'plugins' => $rows,
            'counts' => [
                'total' => $total,
                'active' => $activeCount,
                'inactive' => $total - $activeCount,
                'updates_available' => $updateCount,
            ],
            'checked_at' => $checkedAt,
        ];
    }
}
