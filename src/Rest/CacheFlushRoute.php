<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/cache/flush
 *
 * Best-effort origin cache flush: WordPress object cache, Spinup page cache
 * if those helpers exist, and WP Engine varnish if present.
 */
class CacheFlushRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/cache/flush', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $actions = [];

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $actions[] = 'wp_cache_flush';
        }

        if (function_exists('spinupwp_purge_site')) {
            spinupwp_purge_site();
            $actions[] = 'spinupwp_purge_site';
        } elseif (function_exists('spinupwp_purge_all')) {
            spinupwp_purge_all();
            $actions[] = 'spinupwp_purge_all';
        }

        if (class_exists('WpeCommon')) {
            if (method_exists('WpeCommon', 'purge_varnish_cache')) {
                \WpeCommon::purge_varnish_cache();
                $actions[] = 'wpe_purge_varnish_cache';
            }
            if (method_exists('WpeCommon', 'purge_memcached')) {
                \WpeCommon::purge_memcached();
                $actions[] = 'wpe_purge_memcached';
            }
        }

        do_action('clockwork_companion_cache_flush');

        return new WP_REST_Response([
            'ok' => true,
            'detail' => $actions === [] ? 'No cache helpers available' : implode(', ', $actions),
            'actions' => $actions,
        ]);
    }
}
