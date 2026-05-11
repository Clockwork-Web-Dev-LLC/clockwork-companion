<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Resource\Repository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/resource-report?since=<iso8601>&limit=<int>
 *
 * Returns hourly resource buckets (cpu_us, wall_us, mem_peak, requests) with
 * bucket_at >= the cursor. Clockwork's ingest loop calls this every 15 min
 * with `since = <last successfully ingested bucket>`. Idempotent — re-pulling
 * the same window is fine on the Clockwork side (UPSERT).
 *
 * No body, no side effects, no state mutation. Read-only.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "version": "1.17.0",
 *     "now": "2026-05-11T20:00:00+00:00",
 *     "rows": [
 *       { "bucket_at": "2026-05-11T18:00:00+00:00", "cpu_us_total": 12345, ... },
 *       ...
 *     ]
 *   }
 */
class ResourceReportRoute
{
    private const DEFAULT_SINCE_HOURS = 24;

    private const DEFAULT_LIMIT = 200;

    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/resource-report', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $since = (string) $request->get_param('since');
        if ($since === '') {
            // Default to the last 24h so a first-pull (no cursor yet) gets
            // useful seed data without grabbing the entire 90-day retention.
            $since = gmdate('c', time() - self::DEFAULT_SINCE_HOURS * 3600);
        }

        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $rows = Repository::since($since, $limit);

        // Re-encode bucket_at as ISO-8601 (DB stores 'YYYY-MM-DD HH:MM:SS' UTC).
        foreach ($rows as &$row) {
            $row['bucket_at'] = self::isoFromSqlDatetime((string) $row['bucket_at']);
        }
        unset($row);

        return new WP_REST_Response([
            'ok' => true,
            'version' => CLOCKWORK_COMPANION_VERSION,
            'now' => gmdate('c'),
            'rows' => $rows,
        ]);
    }

    private static function isoFromSqlDatetime(string $sqlDatetime): string
    {
        $ts = strtotime($sqlDatetime.' UTC');
        if ($ts === false) {
            return $sqlDatetime;
        }

        return gmdate('c', $ts);
    }
}
