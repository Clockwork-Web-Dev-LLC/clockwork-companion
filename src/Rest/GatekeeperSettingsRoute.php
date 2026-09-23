<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Gatekeeper\Settings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/gatekeeper-settings
 *
 * Inbound endpoint — Clockwork Control pushes fleet and site-specific
 * Gatekeeper lockout policy and custom message templates here.
 */
class GatekeeperSettingsRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/gatekeeper-settings', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'invalid_body', 'message' => 'Body must be a JSON object'],
                400
            );
        }

        Settings::update($payload);

        return new WP_REST_Response(['ok' => true]);
    }
}
