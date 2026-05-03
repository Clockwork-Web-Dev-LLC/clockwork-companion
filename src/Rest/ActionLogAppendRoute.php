<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\ActionLog\Repository;
use ClockworkCompanion\Auth\HmacVerifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/action-log/append
 *
 * Mirror endpoint — Clockwork posts a row from its action_logs table here
 * after each successful local write. The site stores its own copy in
 * wp_clockwork_action_log so the Tools → Clockwork "Activity" admin page
 * can show clients what we've done.
 *
 * Push-once-from-Clockwork semantics: if Clockwork's call fails, the
 * Companion-side log is permanently missing that row. That's an acceptable
 * trade-off for first-cut — Clockwork still has the authoritative copy in
 * action_logs, and we're not building reconciliation here.
 *
 * Request body: a single normalised row.
 *   {
 *     "action_type": "plugin_update",
 *     "target": "akismet/akismet.php",
 *     "summary": "Updated Akismet 5.3.7 → 5.4.0 on example.com.",
 *     "details": {...},                 // optional
 *     "ok": true,
 *     "error": null,                    // optional
 *     "elapsed_ms": 4321,               // optional
 *     "actor": "manual",
 *     "care_plan_enabled": false,       // for the page banner
 *     "ran_at": "2026-05-03T20:00:00Z"  // ISO-8601, gets converted to UTC
 *   }
 */
class ActionLogAppendRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/action-log/append', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            return new WP_Error('invalid_input', 'Body must be a JSON object', ['status' => 400]);
        }

        $actionType = isset($body['action_type']) ? (string) $body['action_type'] : '';
        $summary = isset($body['summary']) ? (string) $body['summary'] : '';
        if ($actionType === '' || $summary === '') {
            return new WP_Error('invalid_input', 'action_type and summary are required', ['status' => 400]);
        }

        // Coerce ran_at into UTC SQL format. Accept ISO-8601 from Clockwork;
        // store as plain UTC datetime so the table is timezone-agnostic.
        $ranAt = null;
        if (! empty($body['ran_at'])) {
            try {
                $dt = new \DateTimeImmutable((string) $body['ran_at']);
                $ranAt = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                $ranAt = null;
            }
        }

        $ok = Repository::insert([
            'action_type' => $actionType,
            'target' => isset($body['target']) ? (string) $body['target'] : null,
            'summary' => $summary,
            'details' => isset($body['details']) && is_array($body['details']) ? $body['details'] : null,
            'ok' => ! empty($body['ok']),
            'error' => isset($body['error']) ? (string) $body['error'] : null,
            'elapsed_ms' => isset($body['elapsed_ms']) ? (int) $body['elapsed_ms'] : null,
            'actor' => isset($body['actor']) ? (string) $body['actor'] : 'manual',
            'care_plan_enabled' => ! empty($body['care_plan_enabled']),
            'ran_at' => $ranAt,
        ]);

        if (! $ok) {
            return new WP_Error('write_failed', 'DB insert failed', ['status' => 500]);
        }

        return new WP_REST_Response(['ok' => true]);
    }
}
