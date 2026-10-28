<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Notifications\ClientNotifications;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET / POST /wp-json/clockwork/v1/client-notifications
 *
 * Bidirectional endpoint for Care Plan & Maintenance Report recipients and
 * client notification destinations (Slack webhook URL).
 *
 * - GET returns the current stored ClientNotifications payload.
 * - POST allows Clockwork Control to push the current report schedule
 *   (recipients, frequency, enabled state, last_sent_at) down to WordPress.
 */
class ClientNotificationsRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/client-notifications', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'handleGet'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'handlePost'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
        ]);
    }

    public function handleGet(): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'client_notifications' => ClientNotifications::payload(),
        ]);
    }

    public function handlePost(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'invalid_body', 'message' => 'Body must be a JSON object'],
                400
            );
        }

        ClientNotifications::save($payload, 'control');

        return new WP_REST_Response([
            'ok' => true,
            'client_notifications' => ClientNotifications::payload(),
        ]);
    }
}

