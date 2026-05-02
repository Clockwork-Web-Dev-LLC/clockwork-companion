<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Stub — full implementation lands in v1.0.x.
 *
 * Accepts { plugin, form_id, marker, mode } and returns
 * { accepted, mail_invoked, mail_outcome, error?, log_excerpt }.
 *
 * mode=lab (default) suppresses real wp_mail send + form-storage CPT writes.
 * mode=live lets the form plugin act normally.
 */
class TestContactFormRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/test-contact-form', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'not_implemented',
        ], 501);
    }
}
