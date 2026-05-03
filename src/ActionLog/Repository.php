<?php

namespace ClockworkCompanion\ActionLog;

/**
 * DB writer + reader for wp_clockwork_action_log. Used by the REST append
 * endpoint (writes) and the Activity admin page (reads).
 *
 * Stays thin — no business logic, just CRUD wrappers around $wpdb. The
 * route validates input shape; this layer trusts what it gets.
 */
class Repository
{
    /**
     * @param  array{
     *     action_type: string,
     *     target?: ?string,
     *     summary: string,
     *     details?: ?array<string, mixed>,
     *     ok?: bool,
     *     error?: ?string,
     *     elapsed_ms?: ?int,
     *     actor?: string,
     *     care_plan_enabled?: bool,
     *     ran_at?: ?string,
     *  }  $row
     */
    public static function insert(array $row): bool
    {
        global $wpdb;

        $details = isset($row['details']) ? wp_json_encode($row['details']) : null;
        $ranAt = $row['ran_at'] ?? gmdate('Y-m-d H:i:s');

        $result = $wpdb->insert(
            Schema::tableName(),
            [
                'action_type' => substr((string) $row['action_type'], 0, 64),
                'target' => isset($row['target']) ? substr((string) $row['target'], 0, 255) : null,
                'summary' => substr((string) $row['summary'], 0, 500),
                'details' => $details,
                'ok' => ! empty($row['ok']) ? 1 : 0,
                'error' => $row['error'] ?? null,
                'elapsed_ms' => isset($row['elapsed_ms']) ? (int) $row['elapsed_ms'] : null,
                'actor' => substr((string) ($row['actor'] ?? 'manual'), 0, 64),
                'care_plan_enabled' => ! empty($row['care_plan_enabled']) ? 1 : 0,
                'ran_at' => $ranAt,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]
        );

        return $result !== false;
    }

    /**
     * Fetch entries within a date window for the admin page. Newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findInWindow(string $startUtc, string $endUtc, int $limit = 500): array
    {
        global $wpdb;
        $table = Schema::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE ran_at >= %s AND ran_at <= %s ORDER BY ran_at DESC LIMIT %d",
                $startUtc,
                $endUtc,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Earliest ran_at across the whole table — used to gate the admin page's
     * "you can scroll back to" hint.
     */
    public static function earliestRanAt(): ?string
    {
        global $wpdb;
        $table = Schema::tableName();

        $val = $wpdb->get_var("SELECT MIN(ran_at) FROM {$table}");
        return $val ? (string) $val : null;
    }

    /**
     * Most recent care_plan_enabled value seen in the log — the admin page
     * uses this to render the right banner ("on a care plan" vs not).
     * Falls back to false when nothing has been logged yet.
     */
    public static function latestCarePlanFlag(): bool
    {
        global $wpdb;
        $table = Schema::tableName();

        $val = $wpdb->get_var("SELECT care_plan_enabled FROM {$table} ORDER BY ran_at DESC LIMIT 1");
        return (bool) $val;
    }

    /**
     * Recent rows of a specific action_type, newest first. Used by the Security
     * sub-page to render scan history without scanning the whole month.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findByActionType(string $actionType, int $limit = 50): array
    {
        global $wpdb;
        $table = Schema::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE action_type = %s ORDER BY ran_at DESC LIMIT %d",
                $actionType,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Most recent row of a given action_type, or null if none exist. Cheap
     * one-liner so the Security page hero card doesn't need to materialize
     * a full result set just to read the latest entry.
     *
     * @return array<string, mixed>|null
     */
    public static function latestByActionType(string $actionType): ?array
    {
        global $wpdb;
        $table = Schema::tableName();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE action_type = %s ORDER BY ran_at DESC LIMIT 1",
                $actionType
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Most recent security_scan row of a specific scan target — 'sitecheck' or
     * 'core_checksums'. The Security admin page renders one card per type and
     * shows that type's last result independently.
     *
     * @return array<string, mixed>|null
     */
    public static function latestByActionLog(string $scanTarget): ?array
    {
        global $wpdb;
        $table = Schema::tableName();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE action_type = %s AND target = %s ORDER BY ran_at DESC LIMIT 1",
                'security_scan',
                $scanTarget
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}
