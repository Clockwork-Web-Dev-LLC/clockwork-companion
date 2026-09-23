<?php

namespace ClockworkCompanion\Gatekeeper;

class Store
{
    /** RFC1918, loopback, and link-local IPv4 ranges — never throttle these. */
    private const LOCAL_V4_CIDRS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
    ];

    /**
     * Checks whether an IP is currently locked out.
     *
     * @return array{ip: string, unlock_at: string, retry_after: int}|null
     */
    public static function isLocked(string $ip): ?array
    {
        if (self::isIgnored($ip)) {
            return null;
        }

        $now = time();

        // 1. Hot cache check
        if (wp_using_ext_object_cache()) {
            $cachedUnlock = wp_cache_get("gatekeeper_locked:{$ip}", 'gatekeeper');
            if (is_numeric($cachedUnlock)) {
                $cachedUnlock = (int) $cachedUnlock;
                if ($cachedUnlock > $now) {
                    return [
                        'ip' => $ip,
                        'unlock_at' => gmdate('c', $cachedUnlock),
                        'retry_after' => max(1, $cachedUnlock - $now),
                    ];
                }
            }
        }

        // 2. Database lookup. unlock_at is stored as UTC via gmdate(); compare
        // against PHP UTC rather than MySQL NOW(), which follows session TZ.
        global $wpdb;
        $table = Schema::tableName();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT ip, unlock_at FROM `{$table}` WHERE ip = %s AND unlock_at IS NOT NULL AND unlock_at > %s LIMIT 1",
            $ip,
            $nowUtc
        ), ARRAY_A);
        $wpdb->suppress_errors(false);

        if (! is_array($row) || empty($row['unlock_at'])) {
            return null;
        }

        $unlockTs = strtotime((string) $row['unlock_at'] . ' UTC');
        if ($unlockTs === false || $unlockTs <= $now) {
            return null;
        }

        $remaining = max(1, $unlockTs - $now);

        if (wp_using_ext_object_cache()) {
            wp_cache_set("gatekeeper_locked:{$ip}", $unlockTs, 'gatekeeper', $remaining);
        }

        return [
            'ip' => $ip,
            'unlock_at' => gmdate('c', $unlockTs),
            'retry_after' => $remaining,
        ];
    }

    /**
     * Records a failed authentication attempt.
     */
    public static function incrementFailure(string $ip): void
    {
        if (self::isIgnored($ip)) {
            return;
        }

        // Floods against an already-locked IP must not bump consecutive_lockouts
        // into the 24h extended window.
        if (self::isLocked($ip) !== null) {
            return;
        }

        $settings = Settings::get();
        $threshold = (int) $settings['threshold'];
        $window = (int) $settings['window_seconds'];

        if (wp_using_ext_object_cache()) {
            $key = "gatekeeper_attempts:{$ip}";
            $attempts = wp_cache_incr($key, 1, 'gatekeeper');
            if ($attempts === false) {
                wp_cache_set($key, 1, 'gatekeeper', $window);
                $attempts = 1;
            } elseif ((int) $attempts === 1) {
                // Redis INCR on a missing key creates it with no TTL, so the
                // attempt window would never expire. Re-set with the window.
                wp_cache_set($key, 1, 'gatekeeper', $window);
            }

            if ((int) $attempts >= $threshold) {
                self::lock($ip);
            }

            self::maybeLazyPrune();

            return;
        }

        // Without persistent object cache: store attempt window on the row.
        global $wpdb;
        $table = Schema::tableName();
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, attempts, attempts_window_start FROM `{$table}` WHERE ip = %s LIMIT 1",
            $ip
        ), ARRAY_A);

        if ($row) {
            $windowStartTs = 0;
            if (! empty($row['attempts_window_start'])) {
                $parsed = strtotime((string) $row['attempts_window_start'] . ' UTC');
                $windowStartTs = $parsed !== false ? $parsed : 0;
            }

            if ($windowStartTs > 0 && time() - $windowStartTs <= $window) {
                $newAttempts = ((int) $row['attempts']) + 1;
                $wpdb->update(
                    $table,
                    ['attempts' => $newAttempts, 'updated_at' => $now],
                    ['id' => $row['id']]
                );

                if ($newAttempts >= $threshold) {
                    self::lock($ip);
                }
            } else {
                $wpdb->update(
                    $table,
                    ['attempts' => 1, 'attempts_window_start' => $now, 'updated_at' => $now],
                    ['id' => $row['id']]
                );
            }
        } else {
            $wpdb->insert($table, [
                'ip' => $ip,
                'consecutive_lockouts' => 0,
                'attempts' => 1,
                'attempts_window_start' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (1 >= $threshold) {
                self::lock($ip);
            }
        }
        $wpdb->suppress_errors(false);

        self::maybeLazyPrune();
    }

    /**
     * Transitions an IP into an active lockout state.
     */
    public static function lock(string $ip): void
    {
        $settings = Settings::get();
        $consecutiveThreshold = (int) $settings['consecutive_lockouts_for_extended'];
        $normalDuration = (int) $settings['lockout_seconds'];
        $extendedDuration = (int) $settings['extended_lockout_seconds'];

        global $wpdb;
        $table = Schema::tableName();
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->suppress_errors(true);
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, consecutive_lockouts, unlock_at FROM `{$table}` WHERE ip = %s LIMIT 1",
            $ip
        ), ARRAY_A);

        if (is_array($existing) && ! empty($existing['unlock_at'])) {
            $existingUnlock = strtotime((string) $existing['unlock_at'] . ' UTC');
            if ($existingUnlock !== false && $existingUnlock > time()) {
                $wpdb->suppress_errors(false);

                return;
            }
        }

        $currentConsecutive = (int) ($existing['consecutive_lockouts'] ?? 0);
        $newConsecutive = $currentConsecutive + 1;

        $duration = ($newConsecutive >= $consecutiveThreshold) ? $extendedDuration : $normalDuration;
        $unlockAtTs = time() + $duration;
        $unlockAt = gmdate('Y-m-d H:i:s', $unlockAtTs);

        if ($existing) {
            $wpdb->update($table, [
                'unlock_at' => $unlockAt,
                'consecutive_lockouts' => $newConsecutive,
                'attempts' => 0,
                'updated_at' => $now,
            ], ['id' => $existing['id']]);
        } else {
            $wpdb->insert($table, [
                'ip' => $ip,
                'unlock_at' => $unlockAt,
                'consecutive_lockouts' => $newConsecutive,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $wpdb->suppress_errors(false);

        if (wp_using_ext_object_cache()) {
            wp_cache_set("gatekeeper_locked:{$ip}", $unlockAtTs, 'gatekeeper', $duration);
            wp_cache_delete("gatekeeper_attempts:{$ip}", 'gatekeeper');
        }
    }

    /**
     * Resets attempt counters on successful login.
     */
    public static function resetOnSuccess(string $ip): void
    {
        global $wpdb;
        $table = Schema::tableName();
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->suppress_errors(true);
        $wpdb->update($table, [
            'attempts' => 0,
            'consecutive_lockouts' => 0,
            'unlock_at' => null,
            'updated_at' => $now,
        ], ['ip' => $ip]);
        $wpdb->suppress_errors(false);

        if (wp_using_ext_object_cache()) {
            wp_cache_delete("gatekeeper_locked:{$ip}", 'gatekeeper');
            wp_cache_delete("gatekeeper_attempts:{$ip}", 'gatekeeper');
        }
    }

    /**
     * Returns all active lockouts across all blogs (multisite-aware).
     *
     * @return array<int, array{ip: string, unlock_at: ?string, source_table: string}>
     */
    public static function activeLockouts(): array
    {
        $siteIds = is_multisite() ? (array) get_sites(['fields' => 'ids', 'number' => 0]) : [null];
        $byIp = [];
        $nowUtc = gmdate('Y-m-d H:i:s');

        foreach ($siteIds as $siteId) {
            if ($siteId !== null) {
                switch_to_blog((int) $siteId);
            }

            global $wpdb;
            $table = Schema::tableName();

            $wpdb->suppress_errors(true);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ip, unlock_at FROM `{$table}` WHERE unlock_at IS NOT NULL AND unlock_at > %s",
                $nowUtc
            ), ARRAY_A);
            $wpdb->suppress_errors(false);

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $ip = trim((string) ($row['ip'] ?? ''));
                    if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                        continue;
                    }

                    $ts = strtotime((string) ($row['unlock_at'] ?? '') . ' UTC');
                    $byIp[$ip] = [
                        'ip' => $ip,
                        'unlock_at' => $ts ? gmdate('c', $ts) : null,
                        'source_table' => 'clockwork_lockouts',
                    ];
                }
            }

            if ($siteId !== null) {
                restore_current_blog();
            }
        }

        return array_values($byIp);
    }

    /**
     * Unlocks a single IP address across native and legacy LLAR stores.
     */
    public static function unlockIp(string $ip): void
    {
        $siteIds = is_multisite() ? (array) get_sites(['fields' => 'ids', 'number' => 0]) : [null];

        foreach ($siteIds as $siteId) {
            if ($siteId !== null) {
                switch_to_blog((int) $siteId);
            }

            global $wpdb;
            $table = Schema::tableName();
            $wpdb->suppress_errors(true);
            $wpdb->delete($table, ['ip' => $ip]);
            self::clearLegacyLlarIp($ip);
            $wpdb->suppress_errors(false);

            if ($siteId !== null) {
                restore_current_blog();
            }
        }

        self::clearLegacyLlarIpNetwork($ip);

        if (wp_using_ext_object_cache()) {
            wp_cache_delete("gatekeeper_locked:{$ip}", 'gatekeeper');
            wp_cache_delete("gatekeeper_attempts:{$ip}", 'gatekeeper');
        }
    }

    /**
     * Unlocks all IPs across native and legacy LLAR stores.
     *
     * @return int Count of cleared IPs
     */
    public static function unlockAll(): int
    {
        $siteIds = is_multisite() ? (array) get_sites(['fields' => 'ids', 'number' => 0]) : [null];
        $clearedIps = [];

        foreach ($siteIds as $siteId) {
            if ($siteId !== null) {
                switch_to_blog((int) $siteId);
            }

            global $wpdb;
            $table = Schema::tableName();

            $wpdb->suppress_errors(true);
            $ips = $wpdb->get_col("SELECT DISTINCT ip FROM `{$table}`");
            if (is_array($ips)) {
                foreach ($ips as $i) {
                    $clearedIps[$i] = true;
                    if (wp_using_ext_object_cache()) {
                        wp_cache_delete("gatekeeper_locked:{$i}", 'gatekeeper');
                        wp_cache_delete("gatekeeper_attempts:{$i}", 'gatekeeper');
                    }
                }
            }
            $wpdb->query("TRUNCATE TABLE `{$table}`");

            $llarTable = $wpdb->prefix . 'limit_login_lockouts';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $llarTable)) === $llarTable) {
                $llarIps = $wpdb->get_col("SELECT DISTINCT ip FROM `{$llarTable}`");
                if (is_array($llarIps)) {
                    foreach ($llarIps as $li) {
                        $clearedIps[$li] = true;
                    }
                }
                $wpdb->query("DELETE FROM `{$llarTable}`");
            }

            $lockouts = (array) get_option('limit_login_lockouts', []);
            foreach (array_keys($lockouts) as $optIp) {
                $clearedIps[$optIp] = true;
            }
            delete_option('limit_login_lockouts');
            delete_option('limit_login_retries');
            delete_option('limit_login_retries_valid');

            $wpdb->suppress_errors(false);

            if ($siteId !== null) {
                restore_current_blog();
            }
        }

        if (is_multisite()) {
            delete_site_option('limit_login_lockouts');
            delete_site_option('limit_login_retries');
            delete_site_option('limit_login_retries_valid');
        }

        return count($clearedIps);
    }

    /**
     * Checks whether an IP is in the ignore/bypass list (loopback, private, or configured).
     *
     * TEST-NET / documentation ranges (203.0.113.0/24, etc.) are public as far
     * as Gatekeeper is concerned — FILTER_FLAG_NO_RES_RANGE would ignore them.
     */
    public static function isIgnored(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true; // invalid IP is never locked
        }

        if (self::isLocalOrPrivate($ip)) {
            return true;
        }

        $settings = Settings::get();

        $ignoreIps = (array) ($settings['ignore_ips'] ?? []);
        if (in_array($ip, $ignoreIps, true)) {
            return true;
        }

        $ignoreCidrs = (array) ($settings['ignore_cidrs'] ?? []);
        foreach ($ignoreCidrs as $cidr) {
            if (is_string($cidr) && self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($mask < 0 || $mask > 32) {
                return false;
            }
            if ($mask === 0) {
                return true;
            }
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $maskLong = -1 << (32 - $mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        return false;
    }

    /**
     * Hook hourly prune. Safe to call when Gatekeeper is disabled so stale
     * rows from a previous enable still get cleaned.
     */
    public static function maybeSchedulePrune(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('clockwork_gatekeeper_prune', [self::class, 'prune']);

        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        if (! wp_next_scheduled('clockwork_gatekeeper_prune')) {
            wp_schedule_event(time() + 3600, 'hourly', 'clockwork_gatekeeper_prune');
        }
    }

    /**
     * Prune expired lockouts older than 48h with no consecutive history.
     */
    public static function prune(): void
    {
        global $wpdb;
        $table = Schema::tableName();
        $nowUtc = gmdate('Y-m-d H:i:s');
        $cutoff = gmdate('Y-m-d H:i:s', time() - 48 * 3600);

        $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table}`
             WHERE consecutive_lockouts = 0
               AND (unlock_at IS NULL OR unlock_at < %s)
               AND updated_at < %s",
            $nowUtc,
            $cutoff
        ));
        $wpdb->suppress_errors(false);
    }

    private static function maybeLazyPrune(): void
    {
        try {
            if (random_int(1, 50) === 1) {
                self::prune();
            }
        } catch (\Throwable) {
            // random_int can throw; never let prune scheduling break auth.
        }
    }

    private static function isLocalOrPrivate(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::LOCAL_V4_CIDRS as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }

        if ($ip === '::1' || $ip === '::') {
            return true;
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        $b0 = ord($packed[0]);
        $b1 = ord($packed[1]);

        // fc00::/7 unique local
        if (($b0 & 0xfe) === 0xfc) {
            return true;
        }

        // fe80::/10 link-local
        if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) {
            return true;
        }

        return false;
    }

    private static function clearLegacyLlarIp(string $ip): void
    {
        global $wpdb;

        $llarTable = $wpdb->prefix . 'limit_login_lockouts';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $llarTable)) === $llarTable) {
            $wpdb->delete($llarTable, ['ip' => $ip]);
        }

        foreach (['limit_login_lockouts', 'limit_login_retries', 'limit_login_retries_valid'] as $option) {
            $values = (array) get_option($option, []);
            if (isset($values[$ip])) {
                unset($values[$ip]);
                update_option($option, $values);
            }
        }
    }

    private static function clearLegacyLlarIpNetwork(string $ip): void
    {
        if (! is_multisite()) {
            return;
        }

        foreach (['limit_login_lockouts', 'limit_login_retries', 'limit_login_retries_valid'] as $option) {
            $values = (array) get_site_option($option, []);
            if (isset($values[$ip])) {
                unset($values[$ip]);
                update_site_option($option, $values);
            }
        }
    }
}
