<?php

namespace ClockworkCompanion\Maintenance;

use ClockworkCompanion\Updates\TransientRefresher;

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
        if (empty($config['enabled']) || $this->shouldBypass($config)) {
            return;
        }

        $this->render503($config);
    }

    /**
     * True when this request should see the live site even though maintenance
     * mode is on. Clockwork API traffic is allowed by *path* (those routes
     * still HMAC-gate themselves). Presence of Clockwork headers alone is
     * not enough — that used to let anyone skip the 503 by sending junk
     * X-Clockwork-* values.
     *
     * @param  array<string, mixed>  $config
     */
    public function shouldBypass(array $config): bool
    {
        if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('current_user_can') && current_user_can('manage_options')) {
            return true;
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if (! empty($config['allowed_ips']) && is_array($config['allowed_ips']) && in_array($clientIp, $config['allowed_ips'], true)) {
            return true;
        }

        if (self::isClockworkApiRequest()) {
            return true;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
        if (str_contains($path, 'wp-login.php')) {
            return true;
        }

        // wp-admin (except the Clockwork ajax action, already handled above)
        // so operators can still sign in and work.
        if (str_contains($path, 'wp-admin')) {
            return true;
        }

        return false;
    }

    /**
     * Clockwork Control talks to /wp-json/clockwork/... (pretty permalinks)
     * or ?rest_route=/clockwork/... (plain permalinks), plus the signed
     * admin-ajax loopback that refreshes premium update transients.
     */
    public static function isClockworkApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '');
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');

        if (str_contains($path, '/wp-json/clockwork/')) {
            return true;
        }

        if ($query !== '') {
            parse_str($query, $params);
            $restRoute = isset($params['rest_route']) ? (string) $params['rest_route'] : '';
            if (str_starts_with($restRoute, '/clockwork/')) {
                return true;
            }
        }

        if (str_contains($path, 'admin-ajax.php')) {
            $action = (string) ($_REQUEST['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '');
            if ($action === TransientRefresher::AJAX_ACTION) {
                return true;
            }
        }

        return false;
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
