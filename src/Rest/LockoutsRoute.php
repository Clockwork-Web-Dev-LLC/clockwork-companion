<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/lockouts
 *
 * Returns active "Limit Login Attempts Reloaded" lockouts for this site.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "lockouts": [
 *       {
 *         "ip": "1.2.3.4",
 *         "unlock_at": "2026-05-02T23:50:00+00:00" | null,
 *         "source_table": "wp_limit_login_lockouts"
 *       },
 *       ...
 *     ]
 *   }
 *
 * Mirrors the shape produced by Clockwork's existing SSH+MySQL puller
 * (App\Services\Llar\LlarLockoutPuller). LLAR stores active lockouts in
 * two places depending on plugin version:
 *   1. A dedicated table `<prefix>limit_login_lockouts` (newer versions).
 *      Schemas seen in the wild:
 *        v2.x: id, ip, lockout_start, lockout_end, reason
 *        v1.x: ip, unlock (unix timestamp)
 *   2. The `<prefix>options` row `limit_login_lockouts`, which holds a
 *      serialized PHP array shaped `ip => unlock_unix_timestamp`.
 *
 * We try the table first (more authoritative), then merge in option-row
 * entries de-duped by IP (table source wins).
 */
class LockoutsRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/lockouts', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $prefix = $wpdb->prefix;
        $tableName = $prefix . 'limit_login_lockouts';
        $optionsTable = $prefix . 'options';

        $byIp = [];

        foreach ($this->fromTable($tableName) as $row) {
            $byIp[$row['ip']] = $row;
        }

        // Option-row entries fill in only IPs the table didn't already supply.
        foreach ($this->fromOptionRow($optionsTable) as $row) {
            $byIp[$row['ip']] ??= $row;
        }

        return new WP_REST_Response([
            'ok' => true,
            'lockouts' => array_values($byIp),
        ]);
    }

    /**
     * @return array<int, array{ip: string, unlock_at: ?string, source_table: string}>
     */
    private function fromTable(string $tableName): array
    {
        global $wpdb;

        // SHOW TABLES LIKE first so we don't trigger a noisy "no such table"
        // error on sites that don't have LLAR installed.
        $existsSql = $wpdb->prepare('SHOW TABLES LIKE %s', $tableName);
        $existing = $wpdb->get_var($existsSql);
        if ($existing !== $tableName) {
            return [];
        }

        // Try the modern v2.x shape first, then fall back to v1.x.
        // $wpdb->get_results() returns null on error (and sets $wpdb->last_error);
        // we treat null as "this column shape doesn't apply" and try the next one.
        $wpdb->suppress_errors(true);
        $sqlV2 = "SELECT ip, lockout_end AS unlock_at FROM `{$tableName}` "
            . 'WHERE lockout_end IS NULL OR lockout_end > NOW()';
        $rows = $wpdb->get_results($sqlV2, ARRAY_A);

        if ($rows === null) {
            // v1.x shape: `unlock` column as unix timestamp.
            $sqlV1 = "SELECT ip, FROM_UNIXTIME(`unlock`) AS unlock_at FROM `{$tableName}` "
                . 'WHERE `unlock` IS NULL OR `unlock` > UNIX_TIMESTAMP()';
            $rows = $wpdb->get_results($sqlV1, ARRAY_A);
        }
        $wpdb->suppress_errors(false);

        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $ip = trim((string) ($row['ip'] ?? ''));
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $unlockRaw = $row['unlock_at'] ?? null;
            $unlockAt = $this->mysqlDatetimeToIso8601($unlockRaw);

            $out[] = [
                'ip' => $ip,
                'unlock_at' => $unlockAt,
                'source_table' => $tableName,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{ip: string, unlock_at: ?string, source_table: string}>
     */
    private function fromOptionRow(string $optionsTable): array
    {
        global $wpdb;

        $wpdb->suppress_errors(true);
        $sql = $wpdb->prepare(
            "SELECT option_value FROM `{$optionsTable}` WHERE option_name = %s LIMIT 1",
            'limit_login_lockouts'
        );
        $value = $wpdb->get_var($sql);
        $wpdb->suppress_errors(false);

        if (! is_string($value) || $value === '') {
            return [];
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);
        if (! is_array($unserialized)) {
            return [];
        }

        $now = time();
        $sourceLabel = $optionsTable . ':limit_login_lockouts';
        $out = [];

        foreach ($unserialized as $ip => $unlockTs) {
            $ip = trim((string) $ip);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $unlockTs = (int) $unlockTs;
            if ($unlockTs > 0 && $unlockTs <= $now) {
                continue; // expired
            }

            $out[] = [
                'ip' => $ip,
                'unlock_at' => $unlockTs > 0 ? gmdate('c', $unlockTs) : null,
                'source_table' => $sourceLabel,
            ];
        }

        return $out;
    }

    /**
     * Convert a MySQL DATETIME string (assumed UTC, as MySQL returns NOW()/FROM_UNIXTIME()
     * in the session timezone — WordPress sets MySQL to UTC by default) into an ISO 8601
     * string. Returns null for null/empty/unparseable inputs so the JSON shape stays
     * `string|null` rather than leaking partial dates downstream.
     */
    private function mysqlDatetimeToIso8601(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $ts = strtotime((string) $value . ' UTC');
        if ($ts === false) {
            return null;
        }

        return gmdate('c', $ts);
    }
}
