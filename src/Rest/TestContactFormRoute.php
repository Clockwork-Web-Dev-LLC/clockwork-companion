<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Admin\Pages\FormsPage;
use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\ContactForm\Tester;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/test-contact-form
 *
 * Body:
 *   { "plugin": "contact-form-7"|"wpforms"|"gravityforms",
 *     "form_id": "<plugin-specific id>",
 *     "marker":  "<unique token; appears in subject + body>",
 *     "mode":    "lab" (default) | "live" }
 *
 * Returns Tester::run() output. HTTP 200 even on rejection — caller
 * inspects ok/accepted/mail_outcome to interpret. HTTP 400 only for
 * payload validation failures.
 */
class TestContactFormRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/test-contact-form', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
            'args' => [
                'plugin' => ['required' => true, 'type' => 'string'],
                'form_id' => ['required' => true, 'type' => 'string'],
                'marker' => ['required' => true, 'type' => 'string'],
                'mode' => ['required' => false, 'type' => 'string', 'default' => 'lab'],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $plugin = (string) $request->get_param('plugin');
        $formId = (string) $request->get_param('form_id');
        $marker = (string) $request->get_param('marker');
        $mode = (string) ($request->get_param('mode') ?: 'lab');

        if ($plugin === '' || $formId === '' || $marker === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'plugin, form_id, and marker are required.',
            ], 400);
        }
        if (! in_array($mode, ['lab', 'live'], true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => "mode must be 'lab' or 'live' (got '{$mode}').",
            ], 400);
        }

        $result = (new Tester())->run($plugin, $formId, $marker, $mode);

        // Persist the outcome so the Forms admin page can render it
        // without re-asking Clockwork. Side-effect only; never alters the
        // wire response. 'lab' AND 'live' both get persisted — admins
        // benefit from seeing every run's status, not just live ones.
        FormsPage::recordResult($plugin, $formId, is_array($result) ? $result : []);

        return new WP_REST_Response($result);
    }
}
