<?php

namespace ClockworkCompanion\ActionLog;

/**
 * Owns the wp_clockwork_action_log table — install + idempotent upgrades.
 *
 * mu-plugins don't have a real activation hook, so we version-gate via
 * a wp_option. Each plugin boot checks the stored version against
 * SCHEMA_VERSION and runs dbDelta if they differ. Cheap (one option read);
 * dbDelta itself is idempotent and only writes when the schema differs.
 *
 * The table name is prefixed with $wpdb->prefix to play nice with multisite
 * (one log per site, not network-wide).
 */
class Schema
{
    /** Bump when the table shape changes. dbDelta will reconcile. */
    public const SCHEMA_VERSION = 1;

    public const VERSION_OPTION = 'clockwork_companion_action_log_schema_version';

    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'clockwork_action_log';
    }

    /**
     * Run on every plugin boot. No-op when the stored schema version matches.
     */
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
            action_type VARCHAR(64) NOT NULL,
            target VARCHAR(255) NULL DEFAULT NULL,
            summary VARCHAR(500) NOT NULL,
            details LONGTEXT NULL,
            ok TINYINT(1) NOT NULL DEFAULT 1,
            error TEXT NULL,
            elapsed_ms INT UNSIGNED NULL,
            actor VARCHAR(64) NOT NULL DEFAULT 'manual',
            care_plan_enabled TINYINT(1) NOT NULL DEFAULT 0,
            ran_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY ran_at (ran_at),
            KEY action_type_ran_at (action_type, ran_at)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
