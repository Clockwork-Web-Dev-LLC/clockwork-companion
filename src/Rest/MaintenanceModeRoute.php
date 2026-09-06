<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Maintenance\MaintenanceGuard;
use WP_REST_Request;
use WP_REST_Response;

class MaintenanceModeRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/maintenance-mode', [
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

    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'maintenance_mode' => MaintenanceGuard::getConfig(),
        ]);
    }

    public function handlePost(WP_REST_Request $request): WP_REST_Response
    {
        $params = (array) $request->get_json_params();

        $current = MaintenanceGuard::getConfig();

        if (array_key_exists('enabled', $params)) {
            $current['enabled'] = (bool) $params['enabled'];
        }
        if (isset($params['title'])) {
            $current['title'] = sanitize_text_field((string) $params['title']);
        }
        if (isset($params['message'])) {
            $current['message'] = sanitize_textarea_field((string) $params['message']);
        }
        if (isset($params['retry_after'])) {
            $current['retry_after'] = max(60, (int) $params['retry_after']);
        }
        if (isset($params['allowed_ips']) && is_array($params['allowed_ips'])) {
            $current['allowed_ips'] = array_values(array_filter(array_map('sanitize_text_field', $params['allowed_ips'])));
        }

        update_option(MaintenanceGuard::OPTION_KEY, $current);

        return new WP_REST_Response([
            'ok' => true,
            'updated' => true,
            'maintenance_mode' => $current,
        ]);
    }
}
