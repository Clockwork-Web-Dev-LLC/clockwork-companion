<?php

namespace ClockworkCompanion\Gatekeeper;

use ClockworkCompanion\Support\RestRequest;
use WP_Error;

/**
 * Hooks Gatekeeper into WordPress authentication.
 *
 * Each login attempt is counted when it starts (earlyLockoutCheck reserves
 * it before the password is checked) and handed back once the credentials
 * prove valid (releaseIfAuthenticated). Counting up front is what makes the
 * threshold hold under parallel requests: with counting on failure only,
 * every request that arrived before the first failure was recorded got its
 * guess in.
 *
 * A successful login never clears earlier failures — otherwise anyone with
 * one low-privilege account could interleave a login of their own between
 * guesses at another account and never be locked out. Failures expire with
 * the attempt window instead.
 */
class Gate
{
    /**
     * Reservations made in this request that have not been resolved yet,
     * per bucket: the attempt count each one produced, oldest first.
     *
     * @var array<string, list<int>>
     */
    private static array $pending = [];

    public static function register(): void
    {
        if (! Settings::enabled()) {
            return;
        }

        // Priority 5: Short-circuit before wp_authenticate_username_password (priority 20)
        // to avoid expensive bcrypt hashing under bot floods.
        add_filter('authenticate', [self::class, 'earlyLockoutCheck'], 5, 3);
        // Just before the 2FA challenge (PHP_INT_MAX), which exits the
        // request once the password is right: the reservation must be handed
        // back before that.
        add_filter('authenticate', [self::class, 'releaseIfAuthenticated'], PHP_INT_MAX - 1, 3);
        add_action('wp_login_failed', [self::class, 'onLoginFailed']);
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

        $client = ClientIp::resolve();
        $ip = $client['ip'];
        if (Store::isIgnored($ip, $client['source'] === ClientIp::SOURCE_PEER)) {
            return $user;
        }

        $lockout = Store::isLocked($ip);

        // Count the attempt now. Core does not treat an empty username or
        // password as a failed login (wp_login_failed is not fired), and a
        // user an earlier filter already authenticated is not a guess.
        if ($lockout === null && ! ($user instanceof \WP_User) && (string) $username !== '' && (string) $password !== '') {
            $count = Store::reserveAttempt($ip);
            if ($count > (int) Settings::get()['threshold']) {
                // More attempts started inside the window than the threshold
                // allows: they are running in parallel, or their requests
                // died before reporting back. Either way, lock now.
                Store::lock($ip);
                $lockout = Store::isLocked($ip);
            } else {
                self::$pending[Store::bucket($ip)][] = $count;
            }
        }

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

    /**
     * Hands back this request's reservation when the credentials were valid.
     *
     * @param mixed $user
     * @return mixed
     */
    public static function releaseIfAuthenticated($user, $username = '', $password = '')
    {
        if (! ($user instanceof \WP_User)) {
            return $user;
        }

        $ip = ClientIp::get();
        $bucket = Store::bucket($ip);
        if (! empty(self::$pending[$bucket])) {
            array_shift(self::$pending[$bucket]);
            Store::releaseAttempt($ip);
        }

        return $user;
    }

    public static function onLoginFailed($username = ''): void
    {
        if (self::isClockworkRest()) {
            return;
        }

        $client = ClientIp::resolve();
        $ip = $client['ip'];
        if (Store::isIgnored($ip, $client['source'] === ClientIp::SOURCE_PEER)) {
            return;
        }

        if (Store::isLocked($ip) !== null) {
            return;
        }

        $bucket = Store::bucket($ip);
        if (! empty(self::$pending[$bucket])) {
            // Already counted when the attempt started.
            $count = (int) array_shift(self::$pending[$bucket]);
            if ($count >= (int) Settings::get()['threshold']) {
                Store::lock($ip);
            }

            return;
        }

        // A failure that did not pass through earlyLockoutCheck (or whose
        // reservation was handed back because a later check rejected valid
        // credentials).
        Store::incrementFailure($ip);
    }

    /**
     * Forget reservations recorded in this process. Tests only: a real
     * request starts with none.
     */
    public static function resetRequestState(): void
    {
        self::$pending = [];
    }

    /**
     * True only when WordPress is dispatching one of this plugin's own REST
     * routes — taken from the parsed route, never from the URL text.
     *
     * A substring test on REQUEST_URI or the query string would let
     * `wp-login.php?x=/wp-json/clockwork/` (or the same on xmlrpc.php) pass
     * as a "Clockwork REST" request: failures would not be recorded and an
     * existing lockout ignored, giving unlimited password guesses.
     * wp-login.php and xmlrpc.php never parse a REST route, so they cannot
     * qualify.
     */
    private static function isClockworkRest(): bool
    {
        return RestRequest::inNamespace(
            RestRequest::dispatchedRoute(),
            [CLOCKWORK_COMPANION_NAMESPACE]
        );
    }
}
