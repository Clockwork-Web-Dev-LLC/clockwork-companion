<?php

namespace ClockworkCompanion\Compat;

/**
 * Allowlist `clockwork` with the perfmatters "Disable REST API" feature.
 *
 * perfmatters (a popular WP performance plugin) lets site owners disable
 * REST API access for unauthenticated users via `add_filter('rest_authentication_errors', ...)`
 * at priority 20. It ships with a hard-coded allowlist of plugin namespaces
 * (contact-form-7, wordfence, elementor, ws-form, etc.) that bypass the
 * restriction, and exposes a `perfmatters_rest_api_exceptions` filter so
 * additional plugins can register themselves.
 *
 * Without this shim, Companion's HMAC-signed REST endpoints get 401'd at
 * WordPress's auth layer BEFORE the per-route permission_callback ever
 * runs — observable as "SSO mint failed: rest_authentication_error" on
 * the per-site page, and silent snapshot-refresh failures.
 *
 * Hook is idempotent and unconditional — if perfmatters isn't installed
 * the filter simply never fires. No version pinning; the filter name has
 * been stable across perfmatters releases.
 */
class PerfmattersCompat
{
    public static function register(): void
    {
        add_filter('perfmatters_rest_api_exceptions', static function ($exceptions) {
            if (! is_array($exceptions)) {
                $exceptions = [];
            }
            if (! in_array('clockwork', $exceptions, true)) {
                $exceptions[] = 'clockwork';
            }

            return $exceptions;
        });
    }
}
