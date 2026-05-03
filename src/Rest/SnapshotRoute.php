<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
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
            'ok' => true,
            'version' => CLOCKWORK_COMPANION_VERSION,
            'captured_at' => gmdate('c'),
            'plugins' => (new PluginsRoute())->payload(),
            'admins' => (new AdminsRoute())->payload(),
            'wp_cron' => (new CronRoute())->payload(),
            'comments_summary' => (new CommentsSummaryRoute())->payload(),
        ]);
    }
}
