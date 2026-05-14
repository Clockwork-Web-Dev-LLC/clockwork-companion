<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\ContactForm\SubscriptionsService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/form-subscriptions
 *
 * Returns the list of forms the local admin has subscribed to recurring
 * testing for. Clockwork's nightly `clockwork:sync-companion-form-
 * subscriptions` command pulls this and reconciles each row into the
 * agency-side `contact_form_tests` table with provenance `client`.
 *
 * Read-only. HMAC-protected like every other Clockwork ↔ Companion route.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "max": 3,
 *     "subscriptions": [
 *       { "form_id": "...", "plugin": "...", "frequency": "weekly",
 *         "enabled": true, "subscribed_at": "ISO8601", "updated_at": "ISO8601" },
 *       ...
 *     ]
 *   }
 */
class FormSubscriptionsRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/form-subscriptions', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $subs = array_values(SubscriptionsService::all());

        return new WP_REST_Response([
            'ok' => true,
            'max' => SubscriptionsService::MAX,
            'subscriptions' => $subs,
        ]);
    }
}
