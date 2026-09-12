<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\WhiteLabel\WhiteLabel;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST Endpoint for White-Label Branding configuration.
 *
 * Route: /wp-json/clockwork/v1/branding
 *
 * Receives HMAC-SHA256 signed white-label configuration pushed from Clockwork Control
 * and persists it to wp_options. When requested via GET, returns the current active
 * branding configuration.
 */
class BrandingRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/branding', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'handlePost'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
            [
                'methods' => 'GET',
                'callback' => [$this, 'handleGet'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
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

        $sanitized = [
            'enabled' => ! empty($payload['enabled']),
            'company_name' => isset($payload['company_name']) ? sanitize_text_field((string) $payload['company_name']) : '',
            'company_url' => isset($payload['company_url']) ? esc_url_raw((string) $payload['company_url']) : '',
            'support_email' => isset($payload['support_email']) ? sanitize_email((string) $payload['support_email']) : '',
            'support_url' => isset($payload['support_url']) ? esc_url_raw((string) $payload['support_url']) : '',
            'plugin_name' => isset($payload['plugin_name']) ? sanitize_text_field((string) $payload['plugin_name']) : '',
            'plugin_description' => isset($payload['plugin_description']) ? sanitize_textarea_field((string) $payload['plugin_description']) : '',
            'menu_title' => isset($payload['menu_title']) ? sanitize_text_field((string) $payload['menu_title']) : '',
            'menu_icon' => isset($payload['menu_icon']) ? sanitize_text_field((string) $payload['menu_icon']) : '',
            'logo_url' => isset($payload['logo_url']) ? esc_url_raw((string) $payload['logo_url']) : '',
            'hide_plugin_row' => ! empty($payload['hide_plugin_row']),
            'hide_help_links' => ! empty($payload['hide_help_links']),
            'footer_text' => isset($payload['footer_text']) ? sanitize_text_field((string) $payload['footer_text']) : '',
            'synced_at' => isset($payload['synced_at']) ? sanitize_text_field((string) $payload['synced_at']) : gmdate('c'),
        ];

        // Also map to legacy author/plugin keys so older Companion helper calls stay backward-compatible
        $sanitized['author_name'] = $sanitized['company_name'];
        $sanitized['author_url'] = $sanitized['company_url'];
        $sanitized['plugin_url'] = $sanitized['company_url'];
        $sanitized['brand_text'] = ! empty($payload['brand_text']) ? sanitize_text_field((string) $payload['brand_text']) : 'Companion';

        if (! empty($payload['primary_color'])) {
            $sanitized['primary_color'] = WhiteLabel::sanitizeHexColor((string) $payload['primary_color']);
        }
        if (! empty($payload['primary_dark_color'])) {
            $sanitized['primary_dark_color'] = WhiteLabel::sanitizeHexColor((string) $payload['primary_dark_color'], '#2D2062');
        } elseif (! empty($sanitized['primary_color'])) {
            $sanitized['primary_dark_color'] = $sanitized['primary_color'];
        }
        if (! empty($payload['accent_color'])) {
            $sanitized['accent_color'] = WhiteLabel::sanitizeHexColor((string) $payload['accent_color'], '#7EFF83');
        }

        update_option(WhiteLabel::OPTION_KEY, $sanitized, false);

        return new WP_REST_Response([
            'ok' => true,
            'updated' => true,
            'branding' => WhiteLabel::getSettings(),
        ], 200);
    }

    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'branding' => WhiteLabel::getSettings(),
        ], 200);
    }
}
