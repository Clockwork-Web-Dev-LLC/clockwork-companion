<?php

namespace ClockworkCompanion\Gatekeeper;

use WP_Error;

class Gate
{
    public static function register(): void
    {
        if (! Settings::enabled()) {
            return;
        }

        // Priority 5: Short-circuit before wp_authenticate_username_password (priority 20)
        // to avoid expensive bcrypt hashing under bot floods.
        add_filter('authenticate', [self::class, 'earlyLockoutCheck'], 5, 3);
        add_action('wp_login_failed', [self::class, 'onLoginFailed']);
        add_action('wp_login', [self::class, 'onLoginSuccess'], 10, 2);
    }

    /**
     * @param mixed $user
     * @param string $username
     * @param string $password
     * @return mixed
     */
    public static function earlyLockoutCheck($user, $username = '', $password = '')
    {
        // Clockwork control plane REST routes must never be locked out.
        if (self::isClockworkRest()) {
            return $user;
        }

        $ip = ClientIp::get();
        if ($ip === 'unknown' || Store::isIgnored($ip)) {
            return $user;
        }

        $lockout = Store::isLocked($ip);
        if ($lockout === null) {
            return $user;
        }

        $error = new WP_Error(
            'gatekeeper_locked',
            'Too many failed login attempts. Your IP address is temporarily locked.',
            ['status' => 429, 'retry_after' => $lockout['retry_after']]
        );

        // Differentiate caller types
        if (defined('XMLRPC_REQUEST') && constant('XMLRPC_REQUEST')) {
            return $error;
        }

        if ((defined('REST_REQUEST') && constant('REST_REQUEST')) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return $error;
        }

        if (defined('WP_CLI') && constant('WP_CLI')) {
            return $error;
        }

        // Browser HTML requests (wp-login.php, custom login pages)
        LockoutPage::renderAndExit($lockout);

        return $user;
    }

    public static function onLoginFailed($username = ''): void
    {
        if (self::isClockworkRest()) {
            return;
        }

        $ip = ClientIp::get();
        if ($ip === 'unknown' || Store::isIgnored($ip)) {
            return;
        }

        if (Store::isLocked($ip) !== null) {
            return;
        }

        Store::incrementFailure($ip);
    }

    /**
     * @param string $username
     * @param mixed $user
     */
    public static function onLoginSuccess($username, $user = null): void
    {
        $ip = ClientIp::get();
        if ($ip === 'unknown') {
            return;
        }

        Store::resetOnSuccess($ip);
    }

    private static function isClockworkRest(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (str_contains($uri, '/wp-json/clockwork/') || str_contains($uri, '/wp-json/clockwork-renegade/')) {
            return true;
        }

        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $restRoute = (string) ($_GET['rest_route'] ?? '');
        $haystack = $uri . '&' . $query . '&' . $restRoute;

        return str_contains($haystack, 'rest_route=/clockwork/')
            || str_contains($haystack, 'rest_route=/clockwork-renegade/')
            || str_contains($haystack, 'rest_route=%2Fclockwork%2F')
            || str_contains($haystack, 'rest_route=%2Fclockwork-renegade%2F');
    }
}
