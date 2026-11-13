<?php

namespace ClockworkCompanion\Gatekeeper;

/**
 * Failed-login counters and lockouts.
 *
 * Bucket: an IPv4 address counts on its own; an IPv6 address counts as its
 * /64, because one client is normally handed a whole /64 and can rotate
 * through it freely. The bucket is stored in the `ip` column as the /64's
 * network address (e.g. 2001:db8:1:2::), which is still a valid IP for
 * callers that list or unlock lockouts.
 *
 * Counting: an attempt is counted when it starts (Gate reserves it before
 * the password is checked) and handed back if the password turns out to be
 * right. The reservation is an atomic increment, so parallel requests cannot
 * all slip past the threshold check before any of them has failed.
 *
 * On multisite the table and the object-cache group are shared by the whole
 * network (see Schema::tableName()).
 */
class Store
{
    /** RFC1918, loopback, and link-local IPv4 ranges. */
    private const LOCAL_V4_CIDRS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
    ];

    public const CACHE_GROUP = 'gatekeeper';

    public const PRUNE_HOOK = 'clockwork_gatekeeper_prune';

    private static bool $cacheGroupRegistered = false;

    /**
     * The key an address is counted and locked under: the address itself for
     * IPv4, the /64 network address for IPv6.
     */
    public static function bucket(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        $network = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return $network === false ? $ip : $network;
    }

    /**
     * Checks whether an IP is currently locked out.
     *
     * @return array{ip: string, unlock_at: string, retry_after: int}|null
     */
    public static function isLocked(string $ip): ?array
    {
        if (self::isIgnored($ip, false)) {
            return null;
        }

        $now = time();
        $bucket = self::bucket($ip);

        // 1. Hot cache check
        if (wp_using_ext_object_cache()) {
            $cachedUnlock = wp_cache_get("gatekeeper_locked:{$bucket}", self::cacheGroup());
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
            $bucket,
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
            wp_cache_set("gatekeeper_locked:{$bucket}", $unlockTs, self::cacheGroup(), $remaining);
        }

        return [
            'ip' => $ip,
            'unlock_at' => gmdate('c', $unlockTs),
            'retry_after' => $remaining,
        ];
    }

    /**
     * Records a failed authentication attempt that was not reserved first,
     * and locks the address when it reaches the threshold.
     */
    public static function incrementFailure(string $ip): void
    {
        if (self::isIgnored($ip, false)) {
            return;
        }

        // Floods against an already-locked IP must not bump consecutive_lockouts
        // into the extended window.
        if (self::isLocked($ip) !== null) {
            return;
        }

        if (self::reserveAttempt($ip) >= (int) Settings::get()['threshold']) {
            self::lock($ip);
        }
    }

    /**
     * Atomically count one attempt and return the count, this one included,
     * within the current window.
     */
    public static function reserveAttempt(string $ip): int
    {
        $bucket = self::bucket($ip);
        $window = (int) Settings::get()['window_seconds'];

        self::maybeLazyPrune();

        if (wp_using_ext_object_cache()) {
            $key = "gatekeeper_attempts:{$bucket}";
            // add() only creates a missing key, atomically and with the window
            // as TTL; incr() on a key that does not exist would create one
            // without a TTL on Redis and never expire.
            wp_cache_add($key, 0, self::cacheGroup(), $window);
            $attempts = wp_cache_incr($key, 1, self::cacheGroup());
            if ($attempts === false) {
                wp_cache_set($key, 1, self::cacheGroup(), $window);
                $attempts = 1;
            }

            return (int) $attempts;
        }

        global $wpdb;
        $table = Schema::tableName();
        $now = gmdate('Y-m-d H:i:s');
        $windowCutoff = gmdate('Y-m-d H:i:s', time() - $window);

        $upsert = static function (bool $returnCount) use ($wpdb, $table, $bucket, $now, $windowCutoff) {
            $next = 'IF(attempts_window_start IS NOT NULL AND attempts_window_start > %s, attempts + 1, 1)';

            return $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}` (ip, consecutive_lockouts, attempts, attempts_window_start, created_at, updated_at)
                 VALUES (%s, 0, 1, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                     attempts = ".($returnCount ? "LAST_INSERT_ID({$next})" : $next).",
                     attempts_window_start = IF(attempts_window_start IS NOT NULL AND attempts_window_start > %s, attempts_window_start, %s),
                     updated_at = %s",
                $bucket,
                $now,
                $now,
                $now,
                $windowCutoff,
                $windowCutoff,
                $now,
                $now
            ));
        };

        $wpdb->suppress_errors(true);
        $result = $upsert(true);

        // LAST_INSERT_ID(expr) hands this connection the value it wrote, so
        // the count needs no second read that a parallel request could race.
        // rows_affected is 1 for a new row (count 1) and 2 for an update.
        $attempts = 0;
        if ($result !== false) {
            $affected = (int) ($wpdb->rows_affected ?? 0);
            if ($affected === 1) {
                $attempts = 1;
            } elseif ($affected === 2 && (int) ($wpdb->insert_id ?? 0) > 0) {
                $attempts = (int) $wpdb->insert_id;
            }
        } else {
            // A database layer without LAST_INSERT_ID(expr) (SQLite
            // drop-ins): plain upsert, then read the count back.
            $upsert(false);
        }

        if ($attempts === 0) {
            $attempts = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT attempts FROM `{$table}` WHERE ip = %s LIMIT 1",
                $bucket
            ));
        }
        $wpdb->suppress_errors(false);

        return max(1, $attempts);
    }

    /**
     * Hand back an attempt reserved by reserveAttempt() whose credentials
     * turned out to be valid. Only ever undoes a reservation: a successful
     * login does not clear failures recorded before it.
     */
    public static function releaseAttempt(string $ip): void
    {
        $bucket = self::bucket($ip);

        if (wp_using_ext_object_cache()) {
            $key = "gatekeeper_attempts:{$bucket}";
            $left = wp_cache_decr($key, 1, self::cacheGroup());
            if (is_int($left) && $left < 0) {
                wp_cache_set($key, 0, self::cacheGroup(), (int) Settings::get()['window_seconds']);
            }

            return;
        }

        global $wpdb;
        $table = Schema::tableName();

        $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET attempts = attempts - 1 WHERE ip = %s AND attempts > 0",
            $bucket
        ));
        $wpdb->suppress_errors(false);
    }

    /**
     * Transitions an IP into an active lockout state.
     *
     * A single conditional UPDATE does the whole transition, so two requests
     * locking the same address at once produce one lockout, not two
     * (which would also count twice towards the extended lockout).
     * consecutive_lockouts only carries over when the previous lockout ended
     * less than `consecutive_reset_seconds` ago.
     */
    public static function lock(string $ip): void
    {
        $settings = Settings::get();
        $consecutiveThreshold = (int) $settings['consecutive_lockouts_for_extended'];
        $normalDuration = (int) $settings['lockout_seconds'];
        $extendedDuration = (int) $settings['extended_lockout_seconds'];
        $resetAfter = (int) $settings['consecutive_reset_seconds'];

        global $wpdb;
        $table = Schema::tableName();
        $bucket = self::bucket($ip);
        $nowTs = time();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $normalUntil = gmdate('Y-m-d H:i:s', $nowTs + $normalDuration);
        $extendedUntil = gmdate('Y-m-d H:i:s', $nowTs + $extendedDuration);
        $decayCutoff = gmdate('Y-m-d H:i:s', $nowTs - $resetAfter);

        $wpdb->suppress_errors(true);

        // MySQL applies single-table SET clauses left to right: the first one
        // reads the old unlock_at, the second sees the new consecutive count.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET
                 consecutive_lockouts = IF(unlock_at IS NOT NULL AND unlock_at > %s, consecutive_lockouts, 0) + 1,
                 unlock_at = IF(consecutive_lockouts >= %d, %s, %s),
                 attempts = 0,
                 updated_at = %s
             WHERE ip = %s AND (unlock_at IS NULL OR unlock_at <= %s)",
            $decayCutoff,
            $consecutiveThreshold,
            $extendedUntil,
            $normalUntil,
            $now,
            $bucket,
            $now
        ));

        if ((int) $updated === 0) {
            // No row to update: either none exists yet, or the address is
            // already locked. INSERT IGNORE creates the first case and leaves
            // the second alone.
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (ip, unlock_at, consecutive_lockouts, attempts, created_at, updated_at)
                 VALUES (%s, %s, 1, 0, %s, %s)",
                $bucket,
                $consecutiveThreshold <= 1 ? $extendedUntil : $normalUntil,
                $now,
                $now
            ));
        }

        $unlockAt = $wpdb->get_var($wpdb->prepare(
            "SELECT unlock_at FROM `{$table}` WHERE ip = %s LIMIT 1",
            $bucket
        ));
        $wpdb->suppress_errors(false);

        if (wp_using_ext_object_cache()) {
            $unlockTs = is_string($unlockAt) ? strtotime($unlockAt . ' UTC') : false;
            if ($unlockTs !== false && $unlockTs > $nowTs) {
                wp_cache_set("gatekeeper_locked:{$bucket}", $unlockTs, self::cacheGroup(), $unlockTs - $nowTs);
            }
            wp_cache_delete("gatekeeper_attempts:{$bucket}", self::cacheGroup());
        }
    }

    /**
     * Returns all active lockouts (one table for the whole network on multisite).
     *
     * @return array<int, array{ip: string, unlock_at: ?string, source_table: string}>
     */
    public static function activeLockouts(): array
    {
        global $wpdb;
        $table = Schema::tableName();
        $byIp = [];

        $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ip, unlock_at FROM `{$table}` WHERE unlock_at IS NOT NULL AND unlock_at > %s",
            gmdate('Y-m-d H:i:s')
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

        return array_values($byIp);
    }

    /**
     * Unlocks a single IP address (or IPv6 /64) across native and legacy LLAR stores.
     */
    public static function unlockIp(string $ip): void
    {
        global $wpdb;

        self::clearNativeIp($ip);

        foreach (self::siteIds() as $siteId) {
            if ($siteId !== null) {
                switch_to_blog((int) $siteId);
            }

            $wpdb->suppress_errors(true);
            self::clearLegacyLlarIp($ip);
            $wpdb->suppress_errors(false);

            if ($siteId !== null) {
                restore_current_blog();
            }
        }

        self::clearLegacyLlarIpNetwork($ip);
    }

    /**
     * Gatekeeper's own part of unlockIp(): the lockout row and the cached
     * counters. LockoutsRoute clears LLAR's stores itself (it also reports
     * what was cleared there), so it calls this directly.
     */
    public static function clearNativeIp(string $ip): void
    {
        global $wpdb;
        $table = Schema::tableName();
        $bucket = self::bucket($ip);

        $wpdb->suppress_errors(true);
        $wpdb->delete($table, ['ip' => $bucket]);
        if ($bucket !== $ip) {
            // Rows written before IPv6 addresses were grouped by /64.
            $wpdb->delete($table, ['ip' => $ip]);
        }
        $wpdb->suppress_errors(false);

        if (wp_using_ext_object_cache()) {
            wp_cache_delete("gatekeeper_locked:{$bucket}", self::cacheGroup());
            wp_cache_delete("gatekeeper_attempts:{$bucket}", self::cacheGroup());
        }
    }

    /**
     * Unlocks all IPs across native and legacy LLAR stores.
     *
     * @return int Count of cleared IPs
     */
    public static function unlockAll(): int
    {
        global $wpdb;
        $clearedIps = array_fill_keys(self::clearNativeAll(), true);

        foreach (self::siteIds() as $siteId) {
            if ($siteId !== null) {
                switch_to_blog((int) $siteId);
            }

            $wpdb->suppress_errors(true);
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
     * Gatekeeper's own part of unlockAll(): empties the lockout table and
     * the cached counters.
     *
     * @return list<string> the addresses (or IPv6 /64s) that had a row
     */
    public static function clearNativeAll(): array
    {
        global $wpdb;
        $table = Schema::tableName();

        $wpdb->suppress_errors(true);
        $ips = $wpdb->get_col("SELECT DISTINCT ip FROM `{$table}`");
        $ips = is_array($ips) ? array_values(array_map('strval', $ips)) : [];
        if (wp_using_ext_object_cache()) {
            foreach ($ips as $i) {
                wp_cache_delete("gatekeeper_locked:{$i}", self::cacheGroup());
                wp_cache_delete("gatekeeper_attempts:{$i}", self::cacheGroup());
            }
        }
        $wpdb->query("TRUNCATE TABLE `{$table}`");
        $wpdb->suppress_errors(false);

        return $ips;
    }

    /**
     * Checks whether an IP is in the ignore/bypass list.
     *
     * $autoIgnorePrivate: private and loopback addresses are skipped when
     * they are the TCP peer — that is a proxy, a health check or the server
     * itself, and counting it would lock out every visitor behind it. An
     * address that came from a forwarding header is the client's own claim,
     * relayed by a trusted proxy; it is not given that pass, or a client
     * could exempt itself by sending a private address.
     *
     * TEST-NET / documentation ranges (203.0.113.0/24, etc.) are public as far
     * as Gatekeeper is concerned — FILTER_FLAG_NO_RES_RANGE would ignore them.
     */
    public static function isIgnored(string $ip, bool $autoIgnorePrivate = true): bool
    {
        if ($ip === 'unknown') {
            return false;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($autoIgnorePrivate && self::isPrivate($ip)) {
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
        if (! is_numeric($mask)) {
            return false;
        }
        $mask = (int) $mask;

        // IPv4 CIDR check (reject mask < 8 or mask > 32 to prevent 0.0.0.0/0 allowlisting everything)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($mask < 8 || $mask > 32) {
                return false;
            }
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $maskLong = -1 << (32 - $mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        // IPv6 CIDR check (reject mask < 16 or mask > 128)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($mask < 16 || $mask > 128) {
                return false;
            }
            $ipBinary = inet_pton($ip);
            $subnetBinary = inet_pton($subnet);
            if ($ipBinary === false || $subnetBinary === false) {
                return false;
            }
            $bytes = (int) floor($mask / 8);
            $bits = $mask % 8;
            if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
                return false;
            }
            if ($bits > 0) {
                $ipByte = ord($ipBinary[$bytes]);
                $subnetByte = ord($subnetBinary[$bytes]);
                $bitMask = (0xFF << (8 - $bits)) & 0xFF;
                if (($ipByte & $bitMask) !== ($subnetByte & $bitMask)) {
                    return false;
                }
            }
            return true;
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

        add_action(self::PRUNE_HOOK, [self::class, 'prune']);

        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        if (! wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + 3600, 'hourly', self::PRUNE_HOOK);
        }
    }

    /**
     * Plugin deactivation: drop the scheduled prune on every site of a
     * network (each site's cron schedules its own), so WordPress is not left
     * calling a hook nothing listens to.
     */
    public static function unschedulePrune(): void
    {
        if (! function_exists('wp_clear_scheduled_hook')) {
            return;
        }

        foreach (self::siteIds() as $siteId) {
            if ($siteId !== null) {
                switch_to_blog((int) $siteId);
            }

            wp_clear_scheduled_hook(self::PRUNE_HOOK);

            if ($siteId !== null) {
                restore_current_blog();
            }
        }
    }

    /**
     * Delete rows that are not locked and have seen no activity for longer
     * than anything still depends on: the attempt window, the period over
     * which consecutive lockouts are remembered, and at least 48 hours.
     */
    public static function prune(): void
    {
        global $wpdb;
        $settings = Settings::get();
        $table = Schema::tableName();
        $nowUtc = gmdate('Y-m-d H:i:s');
        $keep = max(48 * 3600, (int) $settings['window_seconds'], (int) $settings['consecutive_reset_seconds']);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $keep);

        $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table}`
             WHERE (unlock_at IS NULL OR unlock_at < %s)
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

    /**
     * Loopback, RFC 1918, link-local, IPv6 unique-local / link-local. Also
     * true for anything that is not a valid address.
     */
    public static function isPrivate(string $ip): bool
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

    /**
     * Object-cache group for counters and lock markers. Global on multisite,
     * so a lockout recorded on one site is seen, and cleared, on all of them.
     */
    private static function cacheGroup(): string
    {
        if (! self::$cacheGroupRegistered) {
            self::$cacheGroupRegistered = true;
            if (function_exists('wp_cache_add_global_groups')) {
                wp_cache_add_global_groups([self::CACHE_GROUP]);
            }
        }

        return self::CACHE_GROUP;
    }

    /**
     * Every site in the network on multisite (no 100-site cap); [null] otherwise.
     *
     * @return array<int, int|null>
     */
    private static function siteIds(): array
    {
        return is_multisite() ? (array) get_sites(['fields' => 'ids', 'number' => 0]) : [null];
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
