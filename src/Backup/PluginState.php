<?php

namespace ClockworkCompanion\Backup;

use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\Maintenance\MaintenanceGuard;

/**
 * Keeps the plugin's own options through a database restore.
 *
 * Importing a dump drops and recreates the options table in the middle of
 * the apply request. Without this, the restore would also roll back:
 * - the HMAC secret (and any pending/previous secret stored beside it), so
 *   Clockwork could no longer sign requests if it was rotated after the
 *   backup was taken;
 * - the restore state the caller polls, which would read idle or stale;
 * - the maintenance flag set for the restore, which a failed import must
 *   leave on;
 * - the operation lock and the consumed-signature markers that stop a
 *   captured request from being replayed.
 */
class PluginState
{
    /**
     * @return list<string> Exact option names to carry over.
     */
    public static function optionNames(): array
    {
        return [
            Secret::OPTION_KEY,
            RestoreState::OPTION_KEY,
            MaintenanceGuard::OPTION_KEY,
        ];
    }

    /**
     * @return list<string> LIKE patterns (already escaped) of options to carry over.
     */
    public static function optionPatterns(): array
    {
        return [
            str_replace('_', '\\_', Secret::OPTION_KEY).'\\_%',
            'clockwork\\_hmac\\_used\\_%',
            '\\_transient\\_clockwork\\_lock\\_%',
            '\\_transient\\_timeout\\_clockwork\\_lock\\_%',
        ];
    }

    /**
     * Read the current rows.
     *
     * @return array<string, array{value: mixed, autoload: bool|null}> option name => value
     */
    public static function capture(): array
    {
        global $wpdb;

        $captured = [];

        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_results') && method_exists($wpdb, 'prepare') && ! empty($wpdb->options)) {
            $names = self::optionNames();
            $patterns = self::optionPatterns();
            $sql = "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ("
                .implode(', ', array_fill(0, count($names), '%s')).')'
                .str_repeat(' OR option_name LIKE %s', count($patterns));
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($names, $patterns)), ARRAY_A);

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (! is_array($row) || ! isset($row['option_name'])) {
                        continue;
                    }
                    $captured[(string) $row['option_name']] = [
                        'value' => function_exists('maybe_unserialize') ? maybe_unserialize($row['option_value'] ?? '') : ($row['option_value'] ?? ''),
                        'autoload' => isset($row['autoload']) ? in_array((string) $row['autoload'], ['yes', 'on', 'auto-on'], true) : null,
                    ];
                }
            }
        }

        // Without a database to query (or if it returned nothing), the exact
        // names can still be read through the options API.
        foreach (self::optionNames() as $name) {
            if (! isset($captured[$name])) {
                $value = get_option($name, null);
                if ($value !== null) {
                    $captured[$name] = ['value' => $value, 'autoload' => null];
                }
            }
        }

        return $captured;
    }

    /**
     * Write captured rows back after the import.
     *
     * @param  array<string, array{value: mixed, autoload: bool|null}>  $captured
     */
    public static function restore(array $captured): void
    {
        // The options cache still describes the database as it was before
        // the import; update_option() would compare against it and skip the
        // write, or UPDATE a row that is gone.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            foreach (array_keys($captured) as $name) {
                wp_cache_delete($name, 'options');
            }
        }

        foreach ($captured as $name => $row) {
            if ($row['autoload'] === null) {
                update_option($name, $row['value']);
            } else {
                update_option($name, $row['value'], $row['autoload']);
            }
        }
    }
}
