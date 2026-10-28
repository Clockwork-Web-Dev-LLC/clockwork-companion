<?php

namespace ClockworkCompanion\Notifications;

/**
 * Stores and retrieves client-configured notification destinations.
 *
 * Supports:
 *  - Care Plan & Maintenance Report email recipients, frequency, and enabled state
 *  - Slack incoming webhook URL for real-time site/form alerts
 *
 * The monitoring app reads and reconciles this via /snapshot and
 * /client-notifications.
 */
class ClientNotifications
{
    public const OPTION = 'clockwork_companion_client_notifications';

    /** @return array<string, mixed> */
    private static function raw(): array
    {
        $raw = get_option(self::OPTION, []);

        return is_array($raw) ? $raw : [];
    }

    public static function getSlackWebhookUrl(): string
    {
        $data = self::raw();
        $url = $data['slack_webhook_url'] ?? '';

        return is_string($url) ? $url : '';
    }

    /** @return list<string> */
    public static function getReportRecipients(): array
    {
        $data = self::raw();

        return self::sanitizeRecipients($data['report_recipients'] ?? []);
    }

    public static function getReportFrequency(): string
    {
        $data = self::raw();
        $freq = $data['report_frequency'] ?? 'monthly';

        return in_array($freq, ['weekly', 'monthly'], true) ? $freq : 'monthly';
    }

    public static function isReportEnabled(): bool
    {
        $data = self::raw();
        if (! array_key_exists('report_enabled', $data)) {
            return true;
        }

        return (bool) $data['report_enabled'];
    }

    public static function getReportLastSentAt(): ?string
    {
        $data = self::raw();
        $val = $data['report_last_sent_at'] ?? null;

        return (is_string($val) && $val !== '') ? $val : null;
    }

    public static function getReportRecipientsUpdatedAt(): ?string
    {
        $data = self::raw();
        $val = $data['report_recipients_updated_at'] ?? null;

        return (is_string($val) && $val !== '') ? $val : null;
    }

    public static function getReportRecipientsUpdatedBy(): ?string
    {
        $data = self::raw();
        $val = $data['report_recipients_updated_by'] ?? null;

        return (is_string($val) && $val !== '') ? $val : null;
    }

    /**
     * Normalize an array or comma/newline/semicolon-separated string of emails
     * into a deduplicated list of valid lowercase email addresses.
     *
     * @param  mixed  $raw
     * @return list<string>
     */
    public static function sanitizeRecipients($raw): array
    {
        if (is_string($raw)) {
            $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($raw)) {
            $parts = [];
            foreach ($raw as $item) {
                if (is_string($item)) {
                    $sub = preg_split('/[\s,;]+/', $item, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    foreach ($sub as $s) {
                        $parts[] = $s;
                    }
                }
            }
        } else {
            return [];
        }

        $clean = [];
        foreach ($parts as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate === '') {
                continue;
            }
            $sanitized = function_exists('sanitize_email') ? sanitize_email($candidate) : filter_var($candidate, FILTER_SANITIZE_EMAIL);
            $valid = function_exists('is_email') ? (bool) is_email($sanitized) : (bool) filter_var($sanitized, FILTER_VALIDATE_EMAIL);
            if ($valid && $sanitized !== '' && ! in_array($sanitized, $clean, true)) {
                $clean[] = $sanitized;
            }
        }

        return array_values($clean);
    }

    /**
     * Persist notification settings.
     *
     * @param  array<string, mixed>  $data
     * @param  string  $actor  'wp_admin' when saved in WP Admin, 'control' when pushed from Clockwork Control
     */
    public static function save(array $data, string $actor = 'wp_admin'): void
    {
        $current = self::raw();

        if (array_key_exists('slack_webhook_url', $data)) {
            $url = trim((string) $data['slack_webhook_url']);
            // Only store https:// webhook URLs — reject empty or non-https values.
            $current['slack_webhook_url'] = (str_starts_with($url, 'https://')) ? esc_url_raw($url) : '';
        }

        $reportTouched = false;

        if (array_key_exists('report_recipients', $data)) {
            $current['report_recipients'] = self::sanitizeRecipients($data['report_recipients']);
            $reportTouched = true;
        }

        if (array_key_exists('report_frequency', $data)) {
            $freq = strtolower(trim((string) $data['report_frequency']));
            if (in_array($freq, ['weekly', 'monthly'], true)) {
                $current['report_frequency'] = $freq;
                $reportTouched = true;
            }
        }

        if (array_key_exists('report_enabled', $data)) {
            $current['report_enabled'] = (bool) $data['report_enabled'];
            $reportTouched = true;
        }

        if (array_key_exists('report_last_sent_at', $data)) {
            $val = $data['report_last_sent_at'];
            $current['report_last_sent_at'] = (is_string($val) && $val !== '') ? $val : null;
        }

        if ($reportTouched) {
            $current['report_recipients_updated_at'] = isset($data['report_recipients_updated_at']) && is_string($data['report_recipients_updated_at']) && $data['report_recipients_updated_at'] !== ''
                ? $data['report_recipients_updated_at']
                : gmdate('c');
            $current['report_recipients_updated_by'] = $actor;
        }

        update_option(self::OPTION, $current, false);
    }

    /**
     * @return array{
     *     slack_webhook_url: string,
     *     report_recipients: list<string>,
     *     report_frequency: string,
     *     report_enabled: bool,
     *     report_last_sent_at: ?string,
     *     report_recipients_updated_at: ?string,
     *     report_recipients_updated_by: ?string
     * }
     */
    public static function payload(): array
    {
        return [
            'slack_webhook_url' => self::getSlackWebhookUrl(),
            'report_recipients' => self::getReportRecipients(),
            'report_frequency' => self::getReportFrequency(),
            'report_enabled' => self::isReportEnabled(),
            'report_last_sent_at' => self::getReportLastSentAt(),
            'report_recipients_updated_at' => self::getReportRecipientsUpdatedAt(),
            'report_recipients_updated_by' => self::getReportRecipientsUpdatedBy(),
        ];
    }
}
