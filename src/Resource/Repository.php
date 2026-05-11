<?php

namespace ClockworkCompanion\Resource;

/**
 * Read/write for wp_clockwork_resource_hourly.
 *
 * Hot path: `record()` runs on every PHP shutdown. Single UPSERT keyed on the
 * current UTC hour bucket — INSERT on first request of the hour, UPDATE
 * thereafter. One DB write per request; storage is bounded.
 *
 * Cold path: `since()` is called by Clockwork's ingest loop every 15 minutes
 * to pull new rows. Cursor pagination via `bucket_at >= ?`.
 *
 * Retention: 90 days. The 7-day leaderboard pulls a small window, but keeping
 * 90 days of history makes incident retro possible without much storage cost
 * (~26 KB/year/site at 240 bytes/row).
 */
class Repository
{
    private const RETENTION_DAYS = 90;

    /** Run the prune query roughly every Nth insert (lazy, no cron dependency). */
    private const PRUNE_EVERY_N = 1000;

    public static function record(int $cpuUs, int $wallUs, int $memPeak): void
    {
        global $wpdb;

        // Negative deltas can happen if the system clock jumps backward
        // mid-request. Clamp to 0 — better to undercount than to write
        // garbage that breaks SUM aggregations later.
        $cpuUs = max(0, $cpuUs);
        $wallUs = max(0, $wallUs);
        $memPeak = max(0, $memPeak);

        $bucket = self::currentBucket();
        $table = Schema::tableName();

        $sql = "INSERT INTO {$table} (bucket_at, cpu_us_total, wall_us_total, mem_peak_max, requests)
                VALUES (%s, %d, %d, %d, 1)
                ON DUPLICATE KEY UPDATE
                    cpu_us_total = cpu_us_total + VALUES(cpu_us_total),
                    wall_us_total = wall_us_total + VALUES(wall_us_total),
                    mem_peak_max = GREATEST(mem_peak_max, VALUES(mem_peak_max)),
                    requests = requests + 1";

        $wpdb->query($wpdb->prepare($sql, $bucket, $cpuUs, $wallUs, $memPeak));

        if (random_int(1, self::PRUNE_EVERY_N) === 1) {
            self::prune();
        }
    }

    /**
     * Return rows with bucket_at >= $sinceIso, ordered ascending, capped.
     *
     * @param  string  $sinceIso  inclusive lower bound, ISO-8601 ('YYYY-MM-DDTHH:MM:SSZ')
     * @return array<int, array{bucket_at: string, cpu_us_total: int, wall_us_total: int, mem_peak_max: int, requests: int}>
     */
    public static function since(string $sinceIso, int $limit = 200): array
    {
        global $wpdb;
        $table = Schema::tableName();

        // Parse the ISO-8601 cursor; reject anything that doesn't look like a
        // date so a bogus cursor doesn't widen the query to a full table scan.
        $ts = strtotime($sinceIso);
        if ($ts === false) {
            return [];
        }
        $sinceSql = gmdate('Y-m-d H:i:s', $ts);

        $limit = max(1, min(1000, $limit));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT bucket_at, cpu_us_total, wall_us_total, mem_peak_max, requests
                 FROM {$table}
                 WHERE bucket_at >= %s
                 ORDER BY bucket_at ASC
                 LIMIT %d",
                $sinceSql,
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
                'bucket_at' => (string) ($row['bucket_at'] ?? ''),
                'cpu_us_total' => (int) ($row['cpu_us_total'] ?? 0),
                'wall_us_total' => (int) ($row['wall_us_total'] ?? 0),
                'mem_peak_max' => (int) ($row['mem_peak_max'] ?? 0),
                'requests' => (int) ($row['requests'] ?? 0),
            ];
        }

        return $out;
    }

    public static function prune(): void
    {
        global $wpdb;
        $table = Schema::tableName();
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400);

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE bucket_at < %s",
            $cutoff
        ));
    }

    /** Bucket start = current hour truncated, UTC, in 'YYYY-MM-DD HH:00:00' form. */
    private static function currentBucket(): string
    {
        return gmdate('Y-m-d H:00:00');
    }
}
