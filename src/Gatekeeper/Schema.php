<?php

namespace ClockworkCompanion\Gatekeeper;

/**
 * Owns the wp_clockwork_lockouts table — install + idempotent upgrades.
 */
class Schema
{
    public const SCHEMA_VERSION = 1;

    public const VERSION_OPTION = 'clockwork_companion_gatekeeper_schema_version';

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'clockwork_lockouts';
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
}
