<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Admin\Pages\BackupsPage;
use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/backups-report
 *
 * Inbound endpoint — Clockwork pushes backup config + metadata here on a daily
 * schedule. Companion stores the latest report in wp_options and the BackupsPage
 * renders it. Last-write-wins; no history kept on the WP side (the agency is
 * the source of truth, not us).
 *
 * Request body shape:
 *   {
 *     "source": "spinupwp",
 *     "fetched_at": "2026-05-02T18:00:00+00:00",
 *     "config": {
 *       "files": true,
 *       "database": true,
 *       "is_backups_retention_period_enabled": true,
 *       "retention_period": 14,
 *       "next_run_time": "2026-05-03T07:00:00+00:00",
 *       "paths_to_exclude": null,
 *       "storage_provider": {"region": "nyc3", "bucket": "clkwrk01"}
 *     },
 *     "schedules": [...],
 *     "history": [...],
 *     "care_plan_enabled": true,
 *     "retention_days": 90,
 *     "history_files": [...],       // Pressable only — {date, type, bytes, notes} per entry
 *     "history_database": [...],    // same shape, independent cadence
 *     "last_backup_at": "2026-08-29T16:00:00+00:00",
 *     "offsite_archive": {          // Pressable + care-plan only, else absent/null
 *       "active": true,
 *       "last_archived_at": "2026-08-30T05:14:00+00:00",
 *       "fs_download_url": "https://...presigned-s3-url...",
 *       "db_download_url": "https://...presigned-s3-url...",
 *       "download_expires_at": "2026-09-04T05:14:00+00:00"
 *     }
 *   }
 *
 * Response: { "ok": true }
 */
class BackupsReportRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/backups-report', [
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

        // Loose-validate the shape so the admin page can rely on what it pulls
        // back. We deliberately do NOT lock down the schema — the agency may
        // ship richer reports later (per-run history, status, etc.) and we want
        // forward-compat without a plugin upgrade per shape change.
        if (! isset($payload['config']) || ! is_array($payload['config'])) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'missing_config', 'message' => 'Missing required key: config'],
                400
            );
        }

        $stored = [
            'source' => isset($payload['source']) ? (string) $payload['source'] : 'agency',
            'fetched_at' => isset($payload['fetched_at']) ? (string) $payload['fetched_at'] : gmdate('c'),
            'config' => $payload['config'],
            'schedules' => isset($payload['schedules']) && is_array($payload['schedules']) ? $payload['schedules'] : [],
            'history' => isset($payload['history']) && is_array($payload['history']) ? $payload['history'] : [],
            // Pressable-only: filesystem and database backups run on
            // independent cadences (daily vs. hourly) with real per-entry
            // sizes, reported as two separate lists rather than forced into
            // paired rows. Absent/empty on SpinupWP sites.
            'history_files' => isset($payload['history_files']) && is_array($payload['history_files']) ? $payload['history_files'] : [],
            'history_database' => isset($payload['history_database']) && is_array($payload['history_database']) ? $payload['history_database'] : [],
            'last_backup_at' => isset($payload['last_backup_at']) ? (string) $payload['last_backup_at'] : null,
            // Care-plan retention fields drive BackupsPage's banner + pill.
            // Dropping them here made every site render the off-plan 30-day
            // upsell copy no matter what Clockwork pushed.
            'care_plan_enabled' => ! empty($payload['care_plan_enabled']),
            'retention_days' => isset($payload['retention_days']) ? (int) $payload['retention_days'] : 30,
            // 'available' (Pressable — no retention policy to promise, just
            // whatever the host's API currently returns) vs the default
            // 'policy' (SpinupWP — a real 30/90-day window we control).
            'history_scope' => isset($payload['history_scope']) ? (string) $payload['history_scope'] : 'policy',
            // Pressable + care-plan only: a second, independent 90-day copy
            // archived off-host to S3 Glacier by a separate standalone
            // process. Absent/inactive for anything else — BackupsPage
            // renders nothing at all in that case, not a false promise.
            'offsite_archive' => isset($payload['offsite_archive']) && is_array($payload['offsite_archive'])
                ? $payload['offsite_archive']
                : null,
        ];

        update_option(BackupsPage::OPTION, $stored, false);

        return new WP_REST_Response(['ok' => true]);
    }
}
