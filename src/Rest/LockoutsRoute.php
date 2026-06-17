<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET    /wp-json/clockwork/v1/lockouts        — list active LLAR lockouts.
 * DELETE /wp-json/clockwork/v1/lockouts        — clear all lockouts.
 * DELETE /wp-json/clockwork/v1/lockouts?ip=x  — clear one IP.
 *
 * LLAR stores active lockouts in two places depending on plugin version:
 *   1. A dedicated table `<prefix>limit_login_lockouts` (newer versions).
 *      Schemas seen in the wild:
 *        v2.x: id, ip, lockout_start, lockout_end, reason
 *        v1.x: ip, unlock (unix timestamp)
 *   2. The `<prefix>options` row `limit_login_lockouts`, which holds a
 *      serialized PHP array shaped `ip => unlock_unix_timestamp`.
 *
 * We try the table first (more authoritative), then merge in option-row
 * entries de-duped by IP (table source wins). Unlocks clear both sources.
 */
class LockoutsRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/lockouts', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'handle'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'handleDelete'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
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

    public function handleDelete(WP_REST_Request $request): WP_REST_Response
    {
        $ip = $request->get_param('ip');

        if ($ip !== null) {
            $ip = trim((string) $ip);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Invalid IP address.'], 400);
            }

            return $this->unlockIp($ip);
        }

        return $this->unlockAll();
    }

    private function unlockIp(string $ip): WP_REST_Response
    {
        $wasLocked = false;
        $unlockedUsernames = [];

        foreach ($this->siteIds() as $siteId) {
            $this->maybeSwitchBlog($siteId);

            global $wpdb;
            $tableName = $wpdb->prefix . 'limit_login_lockouts';

            $existsSql = $wpdb->prepare('SHOW TABLES LIKE %s', $tableName);
            if ($wpdb->get_var($existsSql) === $tableName) {
                $wpdb->delete($tableName, ['ip' => $ip], ['%s']);
            }

            $lockouts = (array) get_option('limit_login_lockouts', []);
            if (isset($lockouts[$ip])) {
                $wasLocked = true;
            }
            unset($lockouts[$ip]);
            update_option('limit_login_lockouts', $lockouts);

            foreach (['limit_login_retries', 'limit_login_retries_valid'] as $opt) {
                $data = (array) get_option($opt, []);
                unset($data[$ip]);
                update_option($opt, $data);
            }

            $log = (array) get_option('limit_login_logged', []);
            if (isset($log[$ip])) {
                foreach ($log[$ip] as $username => &$entry) {
                    if (! is_array($entry)) {
                        $entry = ['counter' => $entry];
                    }
                    $entry['unlocked'] = true;
                    $unlockedUsernames[] = $username;
                }
                unset($entry);
                update_option('limit_login_logged', $log);
            }

            $this->maybeRestoreBlog($siteId);
        }

        // LLAR "Network/Site Wide" mode stores lockouts in wp_sitemeta rather
        // than per-blog options. Clear those too so a network-level lockout
        // doesn't survive a per-site sweep.
        if (is_multisite()) {
            $netLockouts = (array) get_site_option('limit_login_lockouts', []);
            if (isset($netLockouts[$ip])) {
                $wasLocked = true;
            }
            unset($netLockouts[$ip]);
            update_site_option('limit_login_lockouts', $netLockouts);

            foreach (['limit_login_retries', 'limit_login_retries_valid'] as $opt) {
                $data = (array) get_site_option($opt, []);
                unset($data[$ip]);
                update_site_option($opt, $data);
            }
        }

        return new WP_REST_Response([
            'ok' => true,
            'ip' => $ip,
            'was_locked' => $wasLocked,
            'unlocked_usernames' => array_values(array_unique($unlockedUsernames)),
        ]);
    }

    private function unlockAll(): WP_REST_Response
    {
        $clearedIps = [];

        foreach ($this->siteIds() as $siteId) {
            $this->maybeSwitchBlog($siteId);

            global $wpdb;
            $tableName = $wpdb->prefix . 'limit_login_lockouts';

            $existsSql = $wpdb->prepare('SHOW TABLES LIKE %s', $tableName);
            if ($wpdb->get_var($existsSql) === $tableName) {
                $ips = $wpdb->get_col("SELECT DISTINCT ip FROM `{$tableName}`");
                if (is_array($ips)) {
                    foreach ($ips as $tableIp) {
                        $clearedIps[$tableIp] = true;
                    }
                }
                $wpdb->query("DELETE FROM `{$tableName}`");
            }

            $lockouts = (array) get_option('limit_login_lockouts', []);
            foreach (array_keys($lockouts) as $optIp) {
                $clearedIps[$optIp] = true;
            }

            delete_option('limit_login_lockouts');
            delete_option('limit_login_retries');
            delete_option('limit_login_retries_valid');

            $log = (array) get_option('limit_login_logged', []);
            $changed = false;
            foreach ($log as &$entries) {
                foreach ($entries as &$entry) {
                    if (! is_array($entry)) {
                        $entry = ['counter' => $entry];
                    }
                    if (empty($entry['unlocked'])) {
                        $entry['unlocked'] = true;
                        $changed = true;
                    }
                }
                unset($entry);
            }
            unset($entries);
            if ($changed) {
                update_option('limit_login_logged', $log);
            }

            $this->maybeRestoreBlog($siteId);
        }

        // LLAR "Network/Site Wide" mode stores lockouts in wp_sitemeta rather
        // than per-blog options. Clear those too.
        if (is_multisite()) {
            $netLockouts = (array) get_site_option('limit_login_lockouts', []);
            foreach (array_keys($netLockouts) as $netIp) {
                $clearedIps[$netIp] = true;
            }
            delete_site_option('limit_login_lockouts');
            delete_site_option('limit_login_retries');
            delete_site_option('limit_login_retries_valid');
        }

        return new WP_REST_Response([
            'ok' => true,
            'cleared' => count($clearedIps),
        ]);
    }

    /**
     * Returns all blog IDs on multisite, or [null] on single-site.
     * Null signals "no switch needed" to maybeSwitchBlog/maybeRestoreBlog.
     *
     * @return array<int, int|null>
     */
    private function siteIds(): array
    {
        if (! is_multisite()) {
            return [null];
        }

        $ids = get_sites(['fields' => 'ids', 'number' => 0]);

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    private function maybeSwitchBlog(?int $siteId): void
    {
        if ($siteId !== null) {
            switch_to_blog($siteId);
        }
    }

    private function maybeRestoreBlog(?int $siteId): void
    {
        if ($siteId !== null) {
            restore_current_blog();
        }
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
