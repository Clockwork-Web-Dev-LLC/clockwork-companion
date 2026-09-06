<?php

namespace ClockworkCompanion\Maintenance;

use ClockworkCompanion\Auth\HmacVerifier;

class MaintenanceGuard
{
    public const OPTION_KEY = 'clockwork_companion_maintenance_mode';

    public static function register(): void
    {
        $guard = new self();
        add_action('plugins_loaded', [$guard, 'intercept'], 2);
    }

    public static function getConfig(): array
    {
        $defaults = [
            'enabled' => false,
            'title' => 'Briefly Unavailable for Scheduled Maintenance',
            'message' => 'We are currently performing scheduled maintenance. Normal service will resume shortly.',
            'retry_after' => 3600,
            'allowed_ips' => [],
        ];

        $stored = get_option(self::OPTION_KEY);
        if (! is_array($stored)) {
            return $defaults;
        }

        return array_merge($defaults, $stored);
    }

    public function intercept(): void
    {
        $config = self::getConfig();
        if (empty($config['enabled'])) {
            return;
        }

        // Bypasses:
        // 1. Logged-in admin
        if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('current_user_can') && current_user_can('manage_options')) {
            return;
        }

        // 2. Allowlisted IP
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if (! empty($config['allowed_ips']) && is_array($config['allowed_ips']) && in_array($clientIp, $config['allowed_ips'], true)) {
            return;
        }

        // 3. HMAC-authenticated Clockwork Control API requests must never be blocked
        if (! empty($_SERVER['HTTP_X_CLOCKWORK_SIGNATURE']) || ! empty($_SERVER['HTTP_X_CLOCKWORK_TIMESTAMP'])) {
            return;
        }

        // Do not block WP login page itself so operators can log in
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($uri, 'wp-login.php') || str_contains($uri, 'wp-admin')) {
            return;
        }

        $this->render503($config);
    }

    public function render503(array $config): void
    {
        $retryAfter = (int) ($config['retry_after'] ?? 3600);
        if ($retryAfter <= 0) {
            $retryAfter = 3600;
        }

        if (! headers_sent()) {
            if (function_exists('status_header')) {
                status_header(503);
            } else {
                header('HTTP/1.1 503 Service Temporarily Unavailable');
            }
            header('Retry-After: ' . $retryAfter);
            header('Content-Type: text/html; charset=utf-8');
        }

        $title = esc_html($config['title'] ?? 'Scheduled Maintenance');
        $message = nl2br(esc_html($config['message'] ?? 'We are performing maintenance. Please check back shortly.'));

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            box-sizing: border-box;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            max-width: 540px;
            width: 100%;
            padding: 40px;
            text-align: center;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
        }
        .icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(20, 86, 240, 0.15);
            color: #3daeff;
            margin-bottom: 24px;
        }
        h1 {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 12px 0;
            color: #ffffff;
        }
        p {
            font-size: 15px;
            line-height: 1.6;
            color: #94a3b8;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
        </div>
        <h1>{$title}</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;
        exit;
    }
}
