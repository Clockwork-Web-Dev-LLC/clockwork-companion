<?php

namespace ClockworkCompanion\Gatekeeper;

/**
 * Gatekeeper policy.
 *
 * On multisite the policy is one network option: users and their passwords
 * are shared by every site in the network, so a per-site switch would let
 * guesses move to whichever subsite has the protection off. Sites upgraded
 * from per-site storage read the main site's old value until the policy is
 * saved again (Schema copies it on upgrade).
 */
class Settings
{
    public const OPTION = 'clockwork_gatekeeper_settings';

    /** Trusted-proxy keyword: Cloudflare's published edge ranges (ClientIp::CLOUDFLARE_RANGES). */
    public const PROXY_CLOUDFLARE = 'cloudflare';

    /** Trusted-proxy keyword: loopback, RFC 1918 and IPv6 unique-local peers. */
    public const PROXY_PRIVATE = 'private';

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
            // Peers allowed to tell us the client address through
            // CF-Connecting-IP / X-Forwarded-For: IPs, CIDRs, or the
            // keywords "cloudflare" and "private". Empty: headers ignored.
            'trusted_proxies' => [],
            // A lockout that ended longer ago than this no longer counts
            // towards the extended lockout.
            'consecutive_reset_seconds' => 86400,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $stored = self::stored();

        return array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    /**
     * The saved policy, or null when none was ever saved.
     *
     * @return array<string, mixed>|null
     */
    public static function stored(): ?array
    {
        if (! is_multisite()) {
            $value = get_option(self::OPTION, null);

            return is_array($value) ? $value : null;
        }

        $value = get_site_option(self::OPTION, null);
        if (is_array($value)) {
            return $value;
        }

        // Not migrated yet: the main site's per-site option is what was in force there.
        $legacy = function_exists('get_blog_option') && function_exists('get_main_site_id')
            ? get_blog_option(get_main_site_id(), self::OPTION, null)
            : null;

        return is_array($legacy) ? $legacy : null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function save(array $settings): void
    {
        if (is_multisite()) {
            update_site_option(self::OPTION, $settings);

            return;
        }

        update_option(self::OPTION, $settings, false);
    }

    /**
     * Normalise a list of trusted proxies: IPv4/IPv6 addresses, CIDRs, and
     * the keywords PROXY_CLOUDFLARE / PROXY_PRIVATE. Anything else is dropped.
     *
     * @param mixed $entries Array, or a string separated by commas, spaces or newlines.
     * @return list<string>
     */
    public static function sanitizeProxyList($entries): array
    {
        if (is_string($entries)) {
            $entries = preg_split('/[\s,]+/', $entries) ?: [];
        }
        if (! is_array($entries)) {
            return [];
        }

        $clean = [];
        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $entry = strtolower(trim($entry));
            if ($entry === self::PROXY_CLOUDFLARE || $entry === self::PROXY_PRIVATE) {
                $clean[] = $entry;
            } elseif (filter_var($entry, FILTER_VALIDATE_IP) !== false || self::isValidCidr($entry)) {
                $clean[] = $entry;
            }
        }

        return array_values(array_unique($clean));
    }

    public static function isValidCidr(string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }
        [$sub, $mask] = explode('/', $cidr, 2);
        if (! ctype_digit($mask)) {
            return false;
        }
        $m = (int) $mask;
        if (filter_var($sub, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $m >= 8 && $m <= 32;
        }
        if (filter_var($sub, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $m >= 16 && $m <= 128;
        }

        return false;
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
                ? array_values(array_filter(array_map('trim', $payload['ignore_cidrs']), fn ($c) => is_string($c) && self::isValidCidr($c)))
                : $current['ignore_cidrs'],
            'trusted_proxies' => isset($payload['trusted_proxies'])
                ? self::sanitizeProxyList($payload['trusted_proxies'])
                : $current['trusted_proxies'],
            'consecutive_reset_seconds' => isset($payload['consecutive_reset_seconds']) ? max(3600, min(2592000, (int) $payload['consecutive_reset_seconds'])) : $current['consecutive_reset_seconds'],
        ]);

        // Enabling Gatekeeper leaves Limit Login Attempts Reloaded alone:
        // Clockwork retires LLAR itself, site by site, once it has confirmed
        // Gatekeeper is enforcing there. Deactivating it here would switch
        // LLAR off on every site a policy push enables (a fleet-wide default
        // flip included) without that check. Running both is harmless.
        self::save($merged);
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
