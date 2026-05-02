<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Plugin;
use WP_REST_Request;
use WP_REST_Response;

class HealthRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        global $wp_version;

        return new WP_REST_Response([
            'ok' => true,
            'version' => CLOCKWORK_COMPANION_VERSION,
            'capabilities' => Plugin::CAPABILITIES,
            'wp_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'server_time' => time(),
        ]);
    }
}
