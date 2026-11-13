<?php

namespace ClockworkCompanion\Gatekeeper;

/**
 * Owns the wp_clockwork_lockouts table — install + idempotent upgrades.
 *
 * On multisite there is one table for the whole network, named with the
 * network's base prefix: users log in to every site with the same password,
 * so failures on one site have to count against attempts on all the others.
 * Version 2 moved per-site tables (and the per-site policy) into the
 * network-wide ones.
 *
 * Version 1 is the table an earlier Companion build created, with the same
 * name and columns, so its rows (running lockouts included) carry straight
 * over: on a single site dbDelta finds nothing to change, on a network the
 * main site's table already is the network table and every other site's
 * table is folded into it.
 */
class Schema
{
    public const SCHEMA_VERSION = 2;

    public const VERSION_OPTION = 'clockwork_companion_gatekeeper_schema_version';

    public const TABLE_SUFFIX = 'clockwork_lockouts';

    public static function tableName(): string
    {
        global $wpdb;

        $prefix = is_multisite() && ! empty($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix;

        return $prefix . self::TABLE_SUFFIX;
    }

    public static function ensureInstalled(): void
    {
        $stored = (int) (is_multisite() ? get_site_option(self::VERSION_OPTION, 0) : get_option(self::VERSION_OPTION, 0));
        if ($stored >= self::SCHEMA_VERSION) {
            return;
        }

        self::install();

        if (is_multisite()) {
            self::migrateToNetwork();
            update_site_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
        } else {
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
        }
    }

    private static function install(): void
    {
        global $wpdb;

        $table = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            unlock_at DATETIME NULL,
            consecutive_lockouts INT UNSIGNED NOT NULL DEFAULT 0,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            attempts_window_start DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_ip (ip),
            KEY idx_unlock_at (unlock_at)
        ) {$charsetCollate};";

        if (file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (function_exists('dbDelta')) {
            dbDelta($sql);
        }
    }

    /**
     * Fold every site's own lockout table into the network table (keeping
     * lockouts that are still running) and drop it, then carry the main
     * site's policy over to the network option if none is saved yet.
     */
    private static function migrateToNetwork(): void
    {
        global $wpdb;

        $network = self::tableName();
        $now = gmdate('Y-m-d H:i:s');

        foreach (self::legacySiteTables() as $legacy) {
            $wpdb->suppress_errors(true);
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `{$network}` (ip, unlock_at, consecutive_lockouts, attempts, attempts_window_start, created_at, updated_at)
                 SELECT ip, unlock_at, consecutive_lockouts, 0, NULL, created_at, updated_at FROM `{$legacy}` WHERE unlock_at > %s",
                $now
            ));
            $wpdb->query("DROP TABLE IF EXISTS `{$legacy}`");
            $wpdb->suppress_errors(false);
        }

        if (get_site_option(Settings::OPTION, null) === null) {
            $legacySettings = Settings::stored();
            if (is_array($legacySettings)) {
                update_site_option(Settings::OPTION, $legacySettings);
            }
        }
    }

    /**
     * Per-site lockout tables left by versions that kept one per site: every
     * site's own name except the network table's. Multisite only.
     *
     * @return list<string>
     */
    public static function legacySiteTables(): array
    {
        global $wpdb;

        if (! is_multisite() || ! function_exists('get_sites') || ! method_exists($wpdb, 'get_blog_prefix')) {
            return [];
        }

        $network = self::tableName();
        $tables = [];
        foreach ((array) get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            $name = $wpdb->get_blog_prefix((int) $siteId) . self::TABLE_SUFFIX;
            if ($name === $network) {
                continue;
            }
            $wpdb->suppress_errors(true);
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) === $name;
            $wpdb->suppress_errors(false);
            if ($exists) {
                $tables[] = $name;
            }
        }

        return $tables;
    }
}
