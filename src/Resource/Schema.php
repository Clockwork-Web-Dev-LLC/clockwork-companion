<?php

namespace ClockworkCompanion\Resource;

/**
 * Owns the wp_clockwork_resource_hourly table — install + idempotent upgrades.
 *
 * Hourly bucket per site: every PHP request adds its `getrusage()` delta to
 * the current hour's row. 8 760 rows/year/site keeps storage trivial.
 *
 * Same version-gate pattern as ActionLog\Schema and AuthAudit\Schema — store
 * the schema version in a wp_option, run dbDelta if stored < constant.
 */
class Schema
{
    public const SCHEMA_VERSION = 1;

    public const VERSION_OPTION = 'clockwork_companion_resource_schema_version';

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix.'clockwork_resource_hourly';
    }

    public static function ensureInstalled(): void
    {
        $stored = (int) get_option(self::VERSION_OPTION, 0);
        if ($stored >= self::SCHEMA_VERSION) {
            return;
        }

        self::install();
        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    private static function install(): void
    {
        global $wpdb;

        $table = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bucket_at DATETIME NOT NULL,
            cpu_us_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            wall_us_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mem_peak_max BIGINT UNSIGNED NOT NULL DEFAULT 0,
            requests INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_bucket_at (bucket_at)
        ) {$charsetCollate};";

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
