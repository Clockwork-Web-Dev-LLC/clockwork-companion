<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Updates\TransientRefresher;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/themes
 *
 * Full installed-theme inventory, mirroring PluginsRoute's shape.
 * Reads the update_themes transient, but first asks TransientRefresher to
 * refresh it via a loopback admin-ajax call (rate-limited, shared with
 * PluginsRoute via the same canary timestamp on update_plugins). The
 * loopback makes premium-theme update filters fire — same reasoning as
 * PluginsRoute, see that docblock for the full story.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "items": [
 *       {
 *         "slug": "bb-theme",
 *         "name": "BB Theme",
 *         "version": "1.7.1.5",
 *         "active": true,
 *         "update_available": true,
 *         "new_version": "1.7.1.6",
 *         "auto_update": false
 *       },
 *       ...
 *     ],
 *     "counts": {
 *       "total": 3,
 *       "active": 1,
 *       "updates_available": 1
 *     },
 *     "checked_at": "2026-05-02T20:00:00+00:00" | null
 *   }
 */
class ThemesRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/themes', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
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
        TransientRefresher::refresh();

        $all            = wp_get_themes();
        $activeSlug     = get_stylesheet();
        $updateTransient = get_site_transient('update_themes');
        $autoUpdates    = (array) get_site_option('auto_update_themes', []);

        $updates    = [];
        $checkedAt  = null;

        if (is_object($updateTransient)) {
            if (isset($updateTransient->response) && is_array($updateTransient->response)) {
                $updates = $updateTransient->response;
            }
            if (isset($updateTransient->last_checked) && is_numeric($updateTransient->last_checked)) {
                $checkedAt = gmdate('c', (int) $updateTransient->last_checked);
            }
        }

        $rows        = [];
        $updateCount = 0;

        foreach ($all as $slug => $theme) {
            $hasUpdate  = isset($updates[$slug]);
            $newVersion = $hasUpdate && isset($updates[$slug]['new_version'])
                ? (string) $updates[$slug]['new_version']
                : null;

            if ($hasUpdate) {
                $updateCount++;
            }

            $rows[] = [
                'slug'             => (string) $slug,
                'name'             => (string) $theme->get('Name'),
                'version'          => (string) $theme->get('Version'),
                'active'           => ($slug === $activeSlug),
                'update_available' => $hasUpdate,
                'new_version'      => $newVersion,
                'auto_update'      => in_array($slug, $autoUpdates, true),
            ];
        }

        return [
            'ok'     => true,
            'items'  => $rows,
            'counts' => [
                'total'             => count($rows),
                'active'            => 1,
                'updates_available' => $updateCount,
            ],
            'checked_at' => $checkedAt,
        ];
    }
}
