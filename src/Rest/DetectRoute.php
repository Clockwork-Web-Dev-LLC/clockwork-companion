<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Stub — full implementation lands in v1.0.x.
 *
 * Returns active form plugins (CF7, WPForms, Gravity), forms per plugin,
 * Post SMTP status, and a brief mailer config summary.
 */
class DetectRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/detect', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'not_implemented',
        ], 501);
    }
}
