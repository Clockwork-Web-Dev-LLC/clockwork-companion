<?php

namespace ClockworkCompanion\AuthAudit;

/**
 * Owns the wp_clockwork_auth_failures table — install + idempotent upgrades.
 *
 * Companion to ActionLog\Schema. Same version-gate pattern: store the
 * schema version in a wp_option, check on every boot, run dbDelta if
 * the stored version is behind the constant.
 *
 * Why a separate table from action_log: failed verifications are
 * potentially high-volume (a probing attack can hit hundreds per minute)
 * and have a different retention need (we cap by row count, not by
 * action_type). Keeping them in their own table avoids polluting the
 * client-facing Activity admin page and lets us prune aggressively
 * without affecting action_log retention.
 */
class Schema
{
    public const SCHEMA_VERSION = 1;

    public const VERSION_OPTION = 'clockwork_companion_auth_failures_schema_version';

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix.'clockwork_auth_failures';
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
            reason VARCHAR(64) NOT NULL,
            request_path VARCHAR(255) NOT NULL,
            request_method VARCHAR(8) NOT NULL,
            failed_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_failed_at (failed_at),
            KEY idx_ip_failed_at (ip, failed_at)
        ) {$charsetCollate};";

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
