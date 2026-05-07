<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Admin\Pages\TrafficPage;
use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/traffic-report
 *
 * Inbound endpoint — Clockwork pushes a 30-day rollup of nginx access-log
 * stats here on a daily schedule (06:35 UTC). Companion stores the latest
 * report in wp_options and the TrafficPage renders it. Last-write-wins; no
 * history kept on the WP side (the agency is the source of truth).
 *
 * Push-only by design — Companion does NOT poll back to the agency. The
 * Traffic page is intentionally not "live"; the cadence-honesty banner on
 * the rendered page makes that explicit so clients don't expect realtime
 * counts.
 *
 * Request body shape (loose-validated, see Backups doctrine):
 *   {
 *     "source": "clockwork-monitoring",
 *     "fetched_at": "2026-05-07T18:00:00+00:00",
 *     "refresh_cadence": "daily",
 *     "daily": [
 *       {
 *         "date": "YYYY-MM-DD",
 *         "requests": <int>,
 *         "visits": <int>,        // WP-Engine-style: DISTINCT IP, ex-403, ex-static
 *         "status_2xx": <int>,
 *         "status_3xx": <int>,
 *         "status_4xx": <int>,
 *         "status_5xx": <int>
 *       },
 *       ...
 *     ],
 *     "totals": { "today": N, "week_7d": N, "month_30d": N },
 *     "today_partial": <bool>,    // today's bar may still be growing
 *     "has_data": <bool>,
 *     "top_paths": [ { "path": "/", "hits": N }, ... ],   // most-recent day, capped at 10
 *     "top_paths_date": "YYYY-MM-DD"
 *   }
 *
 * Response: { "ok": true }
 */
class TrafficReportRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/traffic-report', [
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

        if (! isset($payload['daily']) || ! is_array($payload['daily'])) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'missing_daily', 'message' => 'Missing required key: daily'],
                400
            );
        }

        $stored = [
            'source' => isset($payload['source']) ? (string) $payload['source'] : 'clockwork-monitoring',
            'fetched_at' => isset($payload['fetched_at']) ? (string) $payload['fetched_at'] : gmdate('c'),
            'refresh_cadence' => isset($payload['refresh_cadence']) ? (string) $payload['refresh_cadence'] : 'daily',
            'daily' => array_values(array_filter($payload['daily'], 'is_array')),
            'totals' => isset($payload['totals']) && is_array($payload['totals']) ? $payload['totals'] : [],
            'today_partial' => ! empty($payload['today_partial']),
            'has_data' => ! empty($payload['has_data']),
            'top_paths' => isset($payload['top_paths']) && is_array($payload['top_paths'])
                ? array_values(array_filter($payload['top_paths'], 'is_array'))
                : [],
            'top_paths_date' => isset($payload['top_paths_date']) ? (string) $payload['top_paths_date'] : '',
        ];

        update_option(TrafficPage::OPTION, $stored, false);

        return new WP_REST_Response(['ok' => true]);
    }
}
