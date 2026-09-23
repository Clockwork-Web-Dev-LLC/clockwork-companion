<?php

namespace ClockworkCompanion\Gatekeeper;

class Settings
{
    public const OPTION = 'clockwork_gatekeeper_settings';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'threshold' => 4,
            'window_seconds' => 1200,
            'lockout_seconds' => 1200,
            'consecutive_lockouts_for_extended' => 4,
            'extended_lockout_seconds' => 86400,
            'headline' => 'Too many failed login attempts',
            'body' => 'Please wait {duration} before trying again.',
            'support_label' => '',
            'support_email' => '',
            'support_url' => '',
            'show_ip' => true,
            'show_unlock_link' => true,
            'unlock_url' => '',
            'ignore_ips' => [],
            'ignore_cidrs' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::defaults(), $stored);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function update(array $payload): void
    {
        $current = self::get();

        $merged = array_merge($current, [
            'enabled' => isset($payload['enabled']) ? (bool) $payload['enabled'] : $current['enabled'],
            'threshold' => isset($payload['threshold']) ? max(1, min(50, (int) $payload['threshold'])) : $current['threshold'],
            'window_seconds' => isset($payload['window_seconds']) ? max(60, min(86400, (int) $payload['window_seconds'])) : $current['window_seconds'],
            'lockout_seconds' => isset($payload['lockout_seconds']) ? max(60, min(604800, (int) $payload['lockout_seconds'])) : $current['lockout_seconds'],
            'consecutive_lockouts_for_extended' => isset($payload['consecutive_lockouts_for_extended']) ? max(1, min(20, (int) $payload['consecutive_lockouts_for_extended'])) : $current['consecutive_lockouts_for_extended'],
            'extended_lockout_seconds' => isset($payload['extended_lockout_seconds']) ? max(60, min(2592000, (int) $payload['extended_lockout_seconds'])) : $current['extended_lockout_seconds'],
            'headline' => isset($payload['headline']) ? sanitize_text_field((string) $payload['headline']) : $current['headline'],
            'body' => isset($payload['body']) ? sanitize_textarea_field((string) $payload['body']) : $current['body'],
            'support_label' => isset($payload['support_label']) ? sanitize_text_field((string) $payload['support_label']) : $current['support_label'],
            'support_email' => isset($payload['support_email']) ? sanitize_email((string) $payload['support_email']) : $current['support_email'],
            'support_url' => isset($payload['support_url']) ? esc_url_raw((string) $payload['support_url']) : $current['support_url'],
            'show_ip' => isset($payload['show_ip']) ? (bool) $payload['show_ip'] : $current['show_ip'],
            'show_unlock_link' => isset($payload['show_unlock_link']) ? (bool) $payload['show_unlock_link'] : $current['show_unlock_link'],
            'unlock_url' => isset($payload['unlock_url']) ? esc_url_raw((string) $payload['unlock_url']) : $current['unlock_url'],
            'ignore_ips' => isset($payload['ignore_ips']) && is_array($payload['ignore_ips'])
                ? array_values(array_filter(array_map('trim', $payload['ignore_ips']), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false))
                : $current['ignore_ips'],
            'ignore_cidrs' => isset($payload['ignore_cidrs']) && is_array($payload['ignore_cidrs'])
                ? array_values(array_filter(array_map('trim', $payload['ignore_cidrs']), fn ($c) => is_string($c) && str_contains($c, '/')))
                : $current['ignore_cidrs'],
        ]);

        update_option(self::OPTION, $merged, false);
    }

    public static function enabled(): bool
    {
        if (defined('CLOCKWORK_GATEKEEPER_DISABLE') && constant('CLOCKWORK_GATEKEEPER_DISABLE')) {
            return false;
        }

        $settings = self::get();

        return (bool) ($settings['enabled'] ?? false);
    }
}
