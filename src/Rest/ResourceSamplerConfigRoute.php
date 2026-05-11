<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Resource\Sampler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/resource-sampler-config
 *
 * Body: { "enabled": true|false }
 *
 * Toggles whether the per-request resource sampler runs on this site. The
 * underlying flag lives in wp_options['clockwork_companion_resource_sampler_enabled'].
 * When false, Sampler::register() short-circuits before adding the shutdown
 * hook — zero per-request overhead.
 *
 * Takes effect on the NEXT request after this POST; the in-flight request
 * (this one) already has the sampler registered (or not) based on the
 * pre-call state.
 *
 * Use case: once Clockwork has gathered enough leaderboard data, the
 * operator clicks Pause on /capacity — which both stops the Clockwork-side
 * ingest and POSTs `{enabled: false}` to every site so the sampler stops
 * writing rows.
 */
class ResourceSamplerConfigRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/resource-sampler-config', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = json_decode($request->get_body(), true);
        if (! is_array($body) || ! array_key_exists('enabled', $body)) {
            return new WP_Error('invalid_input', "Body must include 'enabled' as bool", ['status' => 400]);
        }

        $enabled = (bool) $body['enabled'];
        Sampler::setEnabled($enabled);

        return new WP_REST_Response([
            'ok' => true,
            'version' => CLOCKWORK_COMPANION_VERSION,
            'enabled' => $enabled,
        ]);
    }
}
