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

        // Merge onto what is stored rather than rebuilding the option from
        // this payload. Keys the payload does not carry — settings Control
        // doesn't push, such as agency_email_domains (which decides who sees
        // the plugin's menu and tools) or menu_icon_url and the extra colours
        // — must survive a branding push instead of silently resetting.
        $sanitized = self::storedSettings();

        $text = ['company_name', 'plugin_name', 'menu_title', 'menu_icon', 'footer_text', 'unlock_hub_domain'];
        foreach ($text as $key) {
            if (array_key_exists($key, $payload)) {
                $sanitized[$key] = sanitize_text_field((string) $payload[$key]);
            }
        }
        foreach (['company_url', 'support_url', 'logo_url'] as $key) {
            if (array_key_exists($key, $payload)) {
                $sanitized[$key] = esc_url_raw((string) $payload[$key]);
            }
        }
        foreach (['enabled', 'hide_plugin_row', 'hide_help_links'] as $key) {
            if (array_key_exists($key, $payload)) {
                $sanitized[$key] = ! empty($payload[$key]);
            }
        }
        if (array_key_exists('support_email', $payload)) {
            $sanitized['support_email'] = sanitize_email((string) $payload['support_email']);
        }
        if (array_key_exists('plugin_description', $payload)) {
            $sanitized['plugin_description'] = sanitize_textarea_field((string) $payload['plugin_description']);
        }
        if (array_key_exists('agency_email_domains', $payload)) {
            $sanitized['agency_email_domains'] = self::sanitizeDomainList($payload['agency_email_domains']);
        }
        $sanitized['synced_at'] = isset($payload['synced_at']) ? sanitize_text_field((string) $payload['synced_at']) : gmdate('c');

        // Also map to legacy author/plugin keys so older Companion helper calls stay backward-compatible
        if (array_key_exists('company_name', $payload)) {
            $sanitized['author_name'] = $sanitized['company_name'];
        }
        if (array_key_exists('company_url', $payload)) {
            $sanitized['author_url'] = $sanitized['company_url'];
            $sanitized['plugin_url'] = $sanitized['company_url'];
        }
        if (array_key_exists('brand_text', $payload) || ! isset($sanitized['brand_text'])) {
            $sanitized['brand_text'] = ! empty($payload['brand_text']) ? sanitize_text_field((string) $payload['brand_text']) : 'Companion';
        }

        if (! empty($payload['primary_color'])) {
            $sanitized['primary_color'] = WhiteLabel::sanitizeHexColor((string) $payload['primary_color']);
        }
        if (! empty($payload['primary_dark_color'])) {
            $sanitized['primary_dark_color'] = WhiteLabel::sanitizeHexColor((string) $payload['primary_dark_color'], '#2D2062');
        } elseif (! empty($payload['primary_color'])) {
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

    /**
     * The stored branding option as saved (no defaults merged in), falling
     * back to the legacy option name the same way WhiteLabel::getSettings()
     * does, so the first push after an upgrade keeps legacy values too.
     *
     * @return array<string, mixed>
     */
    private static function storedSettings(): array
    {
        $saved = get_option(WhiteLabel::OPTION_KEY, null);
        if (! is_array($saved)) {
            $saved = get_option(WhiteLabel::LEGACY_OPTION_KEY, []);
        }

        return is_array($saved) ? $saved : [];
    }

    /**
     * Normalise an agency domain list (string or array; comma, semicolon,
     * whitespace or newline separated; "@domain", "domain" or a full email
     * address) to "domain.tld, other.tld". Anything that is not a plausible
     * hostname is dropped. An empty result is valid and means "no gating".
     *
     * @param  mixed  $value
     */
    private static function sanitizeDomainList($value): string
    {
        if (is_array($value)) {
            $value = implode(',', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $value));
        }
        if (! is_scalar($value)) {
            return '';
        }

        $domains = [];
        foreach (preg_split('/[\s,;]+/', strtolower(trim((string) $value))) ?: [] as $part) {
            if (str_contains($part, '@')) {
                $part = (string) substr((string) strrchr($part, '@'), 1);
            }
            $part = trim($part, '.');
            if ($part !== '' && strlen($part) <= 253
                && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $part)
                && ! in_array($part, $domains, true)
            ) {
                $domains[] = $part;
            }
        }

        return implode(', ', $domains);
    }
}
