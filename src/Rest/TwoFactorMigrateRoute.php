<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\TwoFactor\WflsMigrator;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/two-factor/migrate
 *
 * HMAC-authenticated endpoint that triggers a WFLS → Companion migration
 * for a given user, without requiring them to click through the admin UI.
 * Intended for use by the monitoring app during fleet-scale migrations.
 *
 * Body: { "user_id": 2 }
 *
 * Returns:
 *   { "ok": true, "migrated": true }   — migration succeeded
 *   { "ok": true, "migrated": false }  — nothing to migrate (already on Clockwork or no WFLS row)
 *   { "ok": false, "error": "..." }    — user not found
 */
class TwoFactorMigrateRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/two-factor/migrate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        if (! $userId || ! get_userdata($userId)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'user_not_found'], 422);
        }

        $result = WflsMigrator::migrate($userId);

        return new WP_REST_Response([
            'ok'       => true,
            'migrated' => $result !== false,
        ]);
    }
}
