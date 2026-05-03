<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/plugins
 *
 * Full installed-plugin inventory. Replaces the SSH+wp-cli probe in
 * App\Services\Sites\WpPluginDetector for sites that have Companion.
 *
 * Reads the cached update_plugins transient — WP cron refreshes it every
 * ~12 hours via wp_version_check(). We deliberately do NOT call
 * wp_update_plugins() here; forcing a synchronous check on every snapshot
 * would hammer api.wordpress.org and slow the call by seconds.
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

        $all = get_plugins();
        $activePaths = (array) get_option('active_plugins', []);
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
            $isActive = in_array($slug, $activePaths, true);
            $hasUpdate = isset($updates[$slug]);
            $newVersion = $hasUpdate && isset($updates[$slug]->new_version)
                ? (string) $updates[$slug]->new_version
                : null;

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
