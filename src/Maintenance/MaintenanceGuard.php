<?php

namespace ClockworkCompanion\Maintenance;

use ClockworkCompanion\Support\RestRequest;
use ClockworkCompanion\Updates\TransientRefresher;

class MaintenanceGuard
{
    public const OPTION_KEY = 'clockwork_companion_maintenance_mode';

    /**
     * Plugin::boot() runs inside a `plugins_loaded` callback at priority 5.
     * WordPress never goes back to a priority it has already passed, so the
     * guard has to hook a later one: registered at priority 2 (as it
     * originally was) the callback was simply never called, and maintenance
     * mode stored "enabled" without ever serving a 503.
     */
    public const HOOK_PRIORITY = 6;

    public static function register(): void
    {
        $guard = new self();

        $finished = function_exists('did_action') && did_action('plugins_loaded')
            && function_exists('doing_action') && ! doing_action('plugins_loaded');
        if ($finished) {
            $guard->intercept();

            return;
        }

        add_action('plugins_loaded', [$guard, 'intercept'], self::HOOK_PRIORITY);
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

        if (self::isActiveSsoRedemption()) {
            return true;
        }

        $path = self::requestPath();
        $admin = self::adminPath();

        if ($path === self::loginPath()) {
            return true;
        }

        // Unauthenticated admin-ajax.php actions (other than the signed Clockwork
        // transient refresh handled in isClockworkApiRequest()) must NOT bypass 503.
        if ($path === $admin.'admin-ajax.php') {
            return false;
        }

        // The real wp-admin directory, so operators can reach the login
        // redirect and sign in. Anchored to where WordPress actually lives:
        // a front-end URL that merely has a `wp-admin` segment
        // (`/x/wp-admin/?rest_route=/wp/v2/posts`) is served by index.php and
        // must get the 503 like any other page.
        return $path === rtrim($admin, '/') || str_starts_with($path, $admin);
    }

    private static function requestPath(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /** URL path of the wp-admin directory, with a trailing slash. */
    private static function adminPath(): string
    {
        $path = function_exists('admin_url') ? parse_url((string) admin_url(), PHP_URL_PATH) : null;
        if (! is_string($path) || ! str_ends_with(rtrim($path, '/'), 'wp-admin')) {
            return '/wp-admin/';
        }

        return rtrim($path, '/').'/';
    }

    private static function loginPath(): string
    {
        return substr(self::adminPath(), 0, -strlen('wp-admin/')).'wp-login.php';
    }

    /**
     * Allow genuine, unexpired Clockwork SSO magic-link redemptions through to
     * Sso\Interceptor on `init`, without waving arbitrary `?clockwork_sso=`
     * probes past the 503 screen.
     */
    private static function isActiveSsoRedemption(): bool
    {
        $nonce = isset($_GET['clockwork_sso']) && is_string($_GET['clockwork_sso'])
            ? $_GET['clockwork_sso']
            : '';

        if ($nonce === '' || preg_match('/^[a-f0-9]{48}$/', $nonce) !== 1) {
            return false;
        }

        $stored = get_option('clockwork_sso_'.$nonce);
        if (! is_array($stored)) {
            return false;
        }

        return (int) ($stored['expires_at'] ?? 0) >= time();
    }

    /**
     * REST namespaces Clockwork Control calls on this plugin.
     *
     * @return list<string>
     */
    public static function apiNamespaces(): array
    {
        return [CLOCKWORK_COMPANION_NAMESPACE];
    }

    /**
     * Clockwork Control talks to /wp-json/clockwork/v1/... (pretty permalinks)
     * or ?rest_route=/clockwork/v1/... (plain permalinks), plus the signed
     * admin-ajax loopback that refreshes premium update transients.
     *
     * The route is the one WordPress will dispatch (see RestRequest), not a
     * substring of the URL, and honours a custom REST prefix — so a site that
     * renamed `wp-json` can still be reached, and taken out of maintenance,
     * by Control.
     */
    public static function isClockworkApiRequest(): bool
    {
        if (RestRequest::inNamespace(RestRequest::anticipatedRoute(), self::apiNamespaces())) {
            return true;
        }

        if (self::requestPath() === self::adminPath().'admin-ajax.php') {
            $action = $_REQUEST['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';
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
            // A cached 503 outlives the maintenance window: tell every layer
            // (page cache, CDN, browser) not to keep it.
            if (function_exists('nocache_headers')) {
                nocache_headers();
            } else {
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
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
