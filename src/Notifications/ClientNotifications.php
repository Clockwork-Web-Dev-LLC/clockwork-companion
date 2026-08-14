<?php

namespace ClockworkCompanion\Notifications;

/**
 * Stores and retrieves client-configured notification destinations.
 *
 * Currently supports one channel: a Slack incoming webhook URL.
 * The monitoring app reads this via the /snapshot endpoint and sends
 * client-facing alerts (form failures, site-down) to the configured URL.
 */
class ClientNotifications
{
    public const OPTION = 'clockwork_companion_client_notifications';

    public static function getSlackWebhookUrl(): string
    {
        $raw = get_option(self::OPTION, []);
        $data = is_array($raw) ? $raw : [];
        $url = $data['slack_webhook_url'] ?? '';

        return is_string($url) ? $url : '';
    }

    public static function save(array $data): void
    {
        $current = get_option(self::OPTION, []);
        $current = is_array($current) ? $current : [];

        if (array_key_exists('slack_webhook_url', $data)) {
            $url = trim((string) $data['slack_webhook_url']);
            // Only store https:// webhook URLs — reject empty or non-https values.
            $current['slack_webhook_url'] = (str_starts_with($url, 'https://')) ? esc_url_raw($url) : '';
        }

        update_option(self::OPTION, $current, false);
    }

    /** @return array{slack_webhook_url: string} */
    public static function payload(): array
    {
        return [
            'slack_webhook_url' => self::getSlackWebhookUrl(),
        ];
    }
}
