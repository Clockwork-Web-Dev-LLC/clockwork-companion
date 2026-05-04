<?php

namespace ClockworkCompanion\AuthAudit;

/**
 * Read/write for the wp_clockwork_auth_failures table.
 *
 * Lazy prune on write: every PRUNE_EVERY_N inserts, delete the oldest rows
 * past CAP_ROWS. Avoids needing wp-cron for retention (cron is unreliable
 * on low-traffic sites). The "every N" knob keeps DELETE pressure low —
 * we don't run the prune query on every single insert under attack.
 */
class Repository
{
    /** Hard ceiling on row count. Older rows are deleted on prune. */
    private const CAP_ROWS = 1000;

    /** Run the prune query roughly every Nth insert. */
    private const PRUNE_EVERY_N = 50;

    /**
     * @param  array<string, mixed>  $row
     */
    public static function insert(array $row): void
    {
        global $wpdb;

        $wpdb->insert(
            Schema::tableName(),
            [
                'ip' => (string) ($row['ip'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'request_path' => substr((string) ($row['request_path'] ?? ''), 0, 255),
                'request_method' => substr((string) ($row['request_method'] ?? ''), 0, 8),
                'failed_at' => (string) ($row['failed_at'] ?? gmdate('Y-m-d H:i:s')),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        // Lazy prune. Random gate so different sites don't all prune at once
        // under a coordinated attack (would still be fine, just nicer).
        if (random_int(1, self::PRUNE_EVERY_N) === 1) {
            self::prune();
        }
    }

    /**
     * Trim to CAP_ROWS by deleting oldest rows.
     */
    public static function prune(): void
    {
        global $wpdb;
        $table = Schema::tableName();

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count <= self::CAP_ROWS) {
            return;
        }

        $excess = $count - self::CAP_ROWS;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} ORDER BY failed_at ASC LIMIT %d",
            $excess
        ));
    }

    public static function countSince(int $secondsAgo): int
    {
        global $wpdb;
        $table = Schema::tableName();
        $threshold = gmdate('Y-m-d H:i:s', time() - $secondsAgo);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE failed_at >= %s",
            $threshold
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 20): array
    {
        global $wpdb;
        $table = Schema::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY failed_at DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array{ip: string, count: int, last_failed_at: string}>
     */
    public static function topOffenders(int $secondsAgo, int $limit = 5): array
    {
        global $wpdb;
        $table = Schema::tableName();
        $threshold = gmdate('Y-m-d H:i:s', time() - $secondsAgo);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ip, COUNT(*) AS count, MAX(failed_at) AS last_failed_at
                 FROM {$table}
                 WHERE failed_at >= %s
                 GROUP BY ip
                 ORDER BY count DESC
                 LIMIT %d",
                $threshold,
                $limit
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'ip' => (string) ($row['ip'] ?? ''),
                'count' => (int) ($row['count'] ?? 0),
                'last_failed_at' => (string) ($row['last_failed_at'] ?? ''),
            ];
        }

        return $out;
    }
}
