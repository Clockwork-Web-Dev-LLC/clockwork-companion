<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Admin\Pages\SecurityPage;
use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/security-summary-report
 *
 * Pressable-only counterpart to the SpinupWP scan-card grid on the Security
 * page: two signals Pressable's own API exposes that SpinupWP has no
 * equivalent for — known plugin/theme vulnerabilities (Pressable's own CVE
 * feed) and Defensive Mode's current on/off status (an ops-triggered edge-
 * cache mitigation for traffic spikes/bot attacks — this endpoint only
 * reports status, there is no client-facing toggle).
 *
 * Push-only, last-write-wins, no history — same doctrine as Backups/Traffic.
 *
 * Request body shape (loose-validated):
 *   {
 *     "fetched_at": "2026-08-29T18:00:00+00:00",
 *     "vulnerabilities": {
 *       "plugins": [ { "name": "...", "version": "...", "alerts": [{"title","fixed_in",...}] } ],
 *       "themes": [ ... ]
 *     },
 *     "defensive_mode": { "active": <bool>, "active_until": <int|null> }
 *   }
 *
 * Response: { "ok": true }
 */
class SecuritySummaryReportRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/security-summary-report', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'invalid_body', 'message' => 'Body must be a JSON object'],
                400
            );
        }

        $vulnerabilities = is_array($payload['vulnerabilities'] ?? null) ? $payload['vulnerabilities'] : [];
        $defensiveMode = is_array($payload['defensive_mode'] ?? null) ? $payload['defensive_mode'] : [];

        $stored = [
            'fetched_at' => isset($payload['fetched_at']) ? (string) $payload['fetched_at'] : gmdate('c'),
            'vulnerabilities' => [
                'plugins' => isset($vulnerabilities['plugins']) && is_array($vulnerabilities['plugins'])
                    ? array_values(array_filter($vulnerabilities['plugins'], 'is_array')) : [],
                'themes' => isset($vulnerabilities['themes']) && is_array($vulnerabilities['themes'])
                    ? array_values(array_filter($vulnerabilities['themes'], 'is_array')) : [],
            ],
            'defensive_mode' => [
                'active' => ! empty($defensiveMode['active']),
                'active_until' => isset($defensiveMode['active_until']) ? (int) $defensiveMode['active_until'] : null,
            ],
        ];

        update_option(SecurityPage::PRESSABLE_SUMMARY_OPTION, $stored, false);

        return new WP_REST_Response(['ok' => true]);
    }
}
