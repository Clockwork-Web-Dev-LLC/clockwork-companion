<?php

namespace ClockworkCompanion\Admin;

use ClockworkCompanion\ActionLog\Repository;
use ClockworkCompanion\Admin\Pages\FormsPage;
use ClockworkCompanion\ContactForm\DetectedFormsCache;
use ClockworkCompanion\ContactForm\SubscriptionsService;
use ClockworkCompanion\ContactForm\Tester;
use ClockworkCompanion\WhiteLabel\WhiteLabel;

/**
 * admin-ajax handlers powering the self-service Forms tab. Every action is
 * gated on `manage_options` + a nonce derived from self::NONCE_ACTION, so an
 * unauthenticated request can't subscribe/test a form against your own
 * mailbox.
 *
 * Distinct from the HMAC-gated REST endpoints. The REST endpoints are how
 * Clockwork (the agency) talks to this site; AJAX is how the local WP
 * admin user talks to their own site.
 */
class FormsAjaxHandlers
{
    public const NONCE_ACTION = 'clockwork_companion_forms';

    public const NONCE_NAME = '_cwc_forms_nonce';

    public function register(): void
    {
        add_action('wp_ajax_clockwork_companion_subscribe_form', [$this, 'subscribe']);
        add_action('wp_ajax_clockwork_companion_unsubscribe_form', [$this, 'unsubscribe']);
        add_action('wp_ajax_clockwork_companion_test_form_now', [$this, 'testNow']);
        add_action('wp_ajax_clockwork_companion_redetect_forms', [$this, 'redetect']);
    }

    public function subscribe(): void
    {
        $this->guard();
        $this->requireCarePlan();
        $formId = isset($_POST['form_id']) ? sanitize_text_field((string) $_POST['form_id']) : '';
        $plugin = isset($_POST['plugin']) ? sanitize_text_field((string) $_POST['plugin']) : '';
        if ($formId === '' || $plugin === '') {
            wp_send_json_error(['message' => 'form_id and plugin are required.'], 400);
        }
        if (SubscriptionsService::atCap() && ! SubscriptionsService::isSubscribed($formId)) {
            wp_send_json_error([
                'message' => 'You\'re at the maximum of ' . SubscriptionsService::MAX . ' monitored forms. Unsubscribe one to add another, or contact ' . WhiteLabel::getAuthorName() . ' to raise the limit.',
            ], 409);
        }
        $ok = SubscriptionsService::subscribe($formId, $plugin);
        if (! $ok) {
            wp_send_json_error(['message' => 'Could not subscribe.'], 500);
        }
        wp_send_json_success(['subscribed' => true, 'form_id' => $formId]);
    }

    public function unsubscribe(): void
    {
        $this->guard();
        $formId = isset($_POST['form_id']) ? sanitize_text_field((string) $_POST['form_id']) : '';
        if ($formId === '') {
            wp_send_json_error(['message' => 'form_id is required.'], 400);
        }
        SubscriptionsService::unsubscribe($formId);
        wp_send_json_success(['subscribed' => false, 'form_id' => $formId]);
    }

    public function testNow(): void
    {
        $this->guard();
        $this->requireCarePlan();
        $formId = isset($_POST['form_id']) ? sanitize_text_field((string) $_POST['form_id']) : '';
        $plugin = isset($_POST['plugin']) ? sanitize_text_field((string) $_POST['plugin']) : '';
        if ($formId === '' || $plugin === '') {
            wp_send_json_error(['message' => 'form_id and plugin are required.'], 400);
        }
        // Build a marker that's unique per click — same shape as the
        // Clockwork-initiated test. Mode = lab so we don't actually email
        // the real recipient (Tester intercepts and asserts mail handed off
        // to wp_mail() successfully).
        $marker = '[Clockwork Health Check #' . substr(md5(uniqid('', true)), 0, 8) . ']';
        $result = (new Tester())->run($plugin, $formId, $marker, 'lab');
        if (is_array($result)) {
            FormsPage::recordResult($plugin, $formId, $result);
        }
        wp_send_json_success([
            'ok' => ! empty($result['ok']),
            'mail_outcome' => $result['mail_outcome'] ?? null,
            'error' => $result['error'] ?? null,
        ]);
    }

    public function redetect(): void
    {
        $this->guard();
        $cache = DetectedFormsCache::refresh();
        wp_send_json_success([
            'plugin' => $cache['plugin'],
            'count' => count($cache['forms']),
        ]);
    }

    private function guard(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        $nonce = isset($_POST[self::NONCE_NAME]) ? (string) $_POST[self::NONCE_NAME] : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Bad nonce.'], 403);
        }
    }

    /**
     * Defense-in-depth for the care-plan UI gate on FormsPage. Subscribe and
     * Test-now are care-plan features; an off-plan admin who bypasses the
     * disabled toggles (devtools, curl, custom script) still gets a clean 403
     * with `error: care_plan_required` rather than silently creating a
     * subscription that will never be tested.
     *
     * Unsubscribe and Re-detect deliberately skip this check — letting off-plan
     * users clean up stale subscriptions and continue to demo form detection
     * is the whole point of the "show what they're missing" upsell pattern.
     */
    private function requireCarePlan(): void
    {
        if (! Repository::latestCarePlanFlag()) {
            wp_send_json_error([
                'message' => 'Form monitoring requires an active care plan. Talk to ' . WhiteLabel::getAuthorName() . ' about adding one.',
                'error'   => 'care_plan_required',
            ], 403);
        }
    }
}
