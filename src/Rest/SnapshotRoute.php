<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Notifications\ClientNotifications;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/snapshot
 *
 * One HMAC call returns the full Round-1 snapshot: plugins + admins + cron
 * + comments_summary. Saves three round trips when refreshing every site
 * on a schedule.
 *
 * Each sub-payload is composed in-process by calling the corresponding
 * route's payload() method — no internal HTTP call, no extra signature
 * verification, no recursion through wp_remote_get.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "version": "1.2.0",
 *     "captured_at": "2026-05-02T20:00:00+00:00",
 *     "plugins": {... PluginsRoute payload ...},
 *     "admins": {... AdminsRoute payload ...},
 *     "wp_cron": {... CronRoute payload ...},
 *     "comments_summary": {... CommentsSummaryRoute payload ...}
 *   }
 */
class SnapshotRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/snapshot', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok'               => true,
            'version'          => CLOCKWORK_COMPANION_VERSION,
            'captured_at'      => gmdate('c'),
            'plugins'          => (new PluginsRoute())->payload(),
            'themes'           => (new ThemesRoute())->payload(),
            'wp_core'          => $this->corePayload(),
            'admins'           => (new AdminsRoute())->payload(),
            'wp_cron'          => (new CronRoute())->payload(),
            'comments_summary' => (new CommentsSummaryRoute())->payload(),
            'two_factor'            => (new TwoFactorStatusRoute())->payload(),
            'translations'          => $this->translationsPayload(),
            'environment'           => (new EnvironmentRoute())->summaryPayload(),
            'database'              => (new DatabaseRoute())->summaryPayload(),
            'client_notifications'  => ClientNotifications::payload(),
        ]);
    }

    /**
     * WP core update status. Reads the cached update_core transient.
     *
     * @return array{update_available: bool, current_version: string, new_version: string|null, is_minor_update: bool|null}
     */
    private function corePayload(): array
    {
        $currentVersion = (string) get_bloginfo('version');
        $transient      = get_site_transient('update_core');

        if (! is_object($transient) || empty($transient->updates)) {
            return [
                'update_available' => false,
                'current_version'  => $currentVersion,
                'new_version'      => null,
                'is_minor_update'  => null,
            ];
        }

        // First entry is the recommended update (may be same version = no update).
        $latest = $transient->updates[0];

        if (! isset($latest->version) || $latest->version === $currentVersion) {
            return [
                'update_available' => false,
                'current_version'  => $currentVersion,
                'new_version'      => null,
                'is_minor_update'  => null,
            ];
        }

        $newVersion    = (string) $latest->version;
        $isMinorUpdate = isset($latest->response) && $latest->response === 'autoupdate';

        return [
            'update_available' => true,
            'current_version'  => $currentVersion,
            'new_version'      => $newVersion,
            'is_minor_update'  => $isMinorUpdate,
        ];
    }

    /**
     * WP translations update status. Reads pending translation updates from core.
     *
     * @return array{count: int, update_available: bool, items: array<int, array<string, mixed>>}
     */
    private function translationsPayload(): array
    {
        if (! function_exists('wp_get_translation_updates') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }

        $updates = function_exists('wp_get_translation_updates') ? wp_get_translation_updates() : [];
        $count   = is_array($updates) ? count($updates) : 0;

        $items = [];
        if (is_array($updates)) {
            foreach ($updates as $u) {
                if (is_object($u)) {
                    $items[] = [
                        'type'     => (string) ($u->type ?? ''),
                        'slug'     => (string) ($u->slug ?? ''),
                        'language' => (string) ($u->language ?? ''),
                        'version'  => (string) ($u->version ?? ''),
                        'updated'  => (string) ($u->updated ?? ''),
                        'package'  => (string) ($u->package ?? ''),
                    ];
                }
            }
        }

        return [
            'count'            => $count,
            'update_available' => $count > 0,
            'items'            => $items,
        ];
    }
}
