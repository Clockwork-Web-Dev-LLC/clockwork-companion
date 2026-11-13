<?php

namespace ClockworkCompanion\Updates;

use ClockworkCompanion\Auth\Secret;

/**
 * One autoloaded option recording that this install is set up: every
 * table schema at its current version, and a connection secret created.
 *
 * boot() runs on every front-end request. It used to read the secret and
 * one schema-version option per table, all stored with autoload off — so
 * four or more extra queries per page view on sites without a persistent
 * object cache. When this record matches, none of that runs; it comes with
 * the options WordPress loads anyway. When it does not (first boot after an
 * update or install, a schema bump), the full checks run as before — each
 * table's own version option still decides whether dbDelta is needed, which
 * is also how existing installs migrate — and the record is rewritten.
 */
class InstallState
{
    public const OPTION = 'clockwork_companion_install_state';

    /**
     * @param  array<int, class-string>  $schemaClasses  classes with SCHEMA_VERSION and ensureInstalled()
     */
    public static function ensure(array $schemaClasses): void
    {
        $expected = ['secret' => true, 'schemas' => []];
        foreach ($schemaClasses as $class) {
            $expected['schemas'][$class] = (int) constant($class.'::SCHEMA_VERSION');
        }

        if (get_option(self::OPTION) === $expected) {
            return;
        }

        try {
            $secretReady = Secret::ensure() !== '';
        } catch (\Throwable $e) {
            // Never let the secret bootstrap take the whole site down: every
            // front-end and admin request runs boot(). HmacVerifier answers
            // no_secret until a secret can be stored.
            $secretReady = false;
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[clockwork] secret bootstrap failed: '.$e->getMessage());
            }
        }

        foreach ($schemaClasses as $class) {
            $class::ensureInstalled();
        }

        if ($secretReady) {
            update_option(self::OPTION, $expected, true);
        }
    }
}
