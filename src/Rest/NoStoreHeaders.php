<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Support\RestRequest;

/**
 * Marks every response from this plugin's REST namespace — success or
 * error — as uncacheable.
 *
 * WordPress only sends no-cache headers for logged-in users. These routes
 * are called by Clockwork Control with an HMAC signature and no session, so
 * to a page cache or CDN that stores /wp-json/* they look like anonymous
 * GETs: /snapshot, /admins, /two-factor, /debug-log, /comments and
 * /environment could be stored and handed to anyone requesting the same
 * URL without a signature. `no-store, private` keeps shared caches from
 * keeping them, and DONOTCACHEPAGE tells WordPress page-cache plugins the
 * same thing.
 */
class NoStoreHeaders
{
    public static function register(): void
    {
        add_filter('rest_post_dispatch', [self::class, 'apply'], 10, 3);
    }

    /**
     * @param  mixed  $response
     * @param  mixed  $server
     * @param  mixed  $request
     * @return mixed
     */
    public static function apply($response, $server = null, $request = null)
    {
        if (! is_object($request) || ! method_exists($request, 'get_route')) {
            return $response;
        }
        if (! RestRequest::inNamespace((string) $request->get_route(), [CLOCKWORK_COMPANION_NAMESPACE])) {
            return $response;
        }

        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        if (is_object($response) && method_exists($response, 'header')) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');
        }

        return $response;
    }
}
