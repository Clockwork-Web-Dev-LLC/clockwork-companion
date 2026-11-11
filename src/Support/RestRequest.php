<?php

namespace ClockworkCompanion\Support;

/**
 * Answers "is this request for one of our own REST routes?" from the route
 * WordPress will actually dispatch — never from a substring of the URL.
 *
 * A substring test on REQUEST_URI is satisfied by a query parameter
 * (`/wp-json/wp/v2/users?x=/wp-json/clockwork/v1/`) or by a login URL
 * (`/wp-login.php?x=/wp-json/clockwork/`), so every caller that used one
 * handed its exemption to any request that mentioned our namespace.
 */
final class RestRequest
{
    /**
     * The REST route WordPress has parsed for this request, or null when the
     * request has not been parsed yet or is not a REST request.
     *
     * This is the value `rest_api_loaded()` hands to the REST server, so it is
     * the route that gets dispatched. Use this for anything security-relevant:
     * when the answer is unknown it is null, and callers must treat that as
     * "not ours".
     */
    public static function dispatchedRoute(): ?string
    {
        $wp = $GLOBALS['wp'] ?? null;
        if (! is_object($wp) || ! isset($wp->query_vars) || ! is_array($wp->query_vars)) {
            return null;
        }

        $route = $wp->query_vars['rest_route'] ?? null;
        if (! is_string($route) || $route === '') {
            return null;
        }

        return self::normalize($route);
    }

    /**
     * Best available answer before WordPress has parsed the request (e.g. on
     * `plugins_loaded`): the parsed route when there is one, otherwise the
     * route WP::parse_request() is going to arrive at for this URL.
     *
     * Mirrors core's precedence — a `rest_route` in $_POST, then $_GET, beats
     * the permalink — so `/wp-json/clockwork/v1/health?rest_route=/wp/v2/users`
     * is correctly read as a request for `/wp/v2/users`.
     *
     * Not for authorization decisions: use dispatchedRoute() there.
     */
    public static function anticipatedRoute(): ?string
    {
        $parsed = self::dispatchedRoute();
        if ($parsed !== null) {
            return $parsed;
        }

        if (function_exists('did_action') && did_action('parse_request')) {
            // Parsed, and WordPress found no REST route.
            return null;
        }

        $path = self::relativePath();
        if ($path === null) {
            return null;
        }

        foreach ([$_POST, $_GET] as $bag) {
            if (isset($bag['rest_route'])) {
                return is_string($bag['rest_route']) && $bag['rest_route'] !== ''
                    ? self::normalize($bag['rest_route'])
                    : null;
            }
        }

        if (str_starts_with($path, 'index.php/')) {
            $path = substr($path, strlen('index.php/'));
        }

        $prefix = function_exists('rest_get_url_prefix') ? trim((string) rest_get_url_prefix(), '/') : 'wp-json';
        if ($prefix === '' || ! str_starts_with($path, $prefix.'/')) {
            return null;
        }

        return self::normalize('/'.substr($path, strlen($prefix) + 1));
    }

    /**
     * True when $route is inside one of $namespaces (`clockwork/v1`).
     * Case-insensitive, because the REST server matches routes that way.
     *
     * @param  list<string>  $namespaces
     */
    public static function inNamespace(?string $route, array $namespaces): bool
    {
        if ($route === null) {
            return false;
        }

        foreach ($namespaces as $namespace) {
            $namespace = '/'.trim($namespace, '/');
            if (strcasecmp($route, $namespace) === 0
                || strncasecmp($route, $namespace.'/', strlen($namespace) + 1) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Request path relative to the site's home path, without a leading slash,
     * or null when the request is for a PHP entry point WordPress does not
     * route through its front controller (wp-login.php, xmlrpc.php, wp-admin/…)
     * — those never dispatch a REST route, whatever their query string says.
     */
    private static function relativePath(): ?string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }
        $path = rawurldecode($path);

        $home = function_exists('home_url') ? parse_url((string) home_url(), PHP_URL_PATH) : null;
        $home = is_string($home) ? rtrim($home, '/') : '';
        if ($home !== '' && (str_starts_with($path, $home.'/') || $path === $home)) {
            $path = substr($path, strlen($home));
        }
        $path = ltrim($path, '/');

        if (preg_match('#(?:^|/)wp-admin(?:/|$)#', $path) === 1) {
            return null;
        }
        if (preg_match('#\.php$#i', $path) === 1 && $path !== 'index.php') {
            return null;
        }

        return $path;
    }

    private static function normalize(string $route): string
    {
        // rest_api_loaded() untrailingslashes the route and treats empty as "/".
        $route = rtrim($route, '/');

        return $route === '' ? '/' : $route;
    }
}
