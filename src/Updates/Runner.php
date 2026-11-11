<?php

namespace ClockworkCompanion\Updates;

use Plugin_Upgrader;
use WP_Ajax_Upgrader_Skin;

/**
 * Single-plugin upgrade runner. Owns the dance that wp-admin's bulk-update
 * page does when you tick a plugin and click Update:
 *
 *   1. Validate the slug exists.
 *   2. Capture before_version + was_active.
 *   3. Force a fresh wp_update_plugins() so the upgrader sees the latest.
 *   4. Initialise WP_Filesystem (direct mode — SpinupWP runs PHP as the file
 *      owner so we don't fall back to FTP credentials prompts).
 *   5. Run Plugin_Upgrader::upgrade().
 *   6. Capture after_version.
 *   7. Re-activate if it was active before. Plugin_Upgrader::upgrade() does
 *      NOT re-activate; if you don't do this yourself, the site is left
 *      running with the plugin off — the dangerous failure mode. If
 *      re-activation fails (corrupted swap, missing files, a fatal on
 *      activation), the whole call reports ok=false — a plugin the site
 *      needs, ending up OFF, is a failure regardless of whether the file
 *      copy itself succeeded. Silently reporting ok=true here is what let
 *      broken updates sail through the nightly job as "complete."
 *   8. Return structured result.
 *
 * Pulled out of the route so we can test the dance without standing up the
 * REST stack.
 */
class Runner
{
    /**
     * @return array{
     *     ok: bool,
     *     upgrade_completed?: bool,
     *     slug: string,
     *     before_version: string,
     *     after_version: ?string,
     *     was_active: bool,
     *     reactivated: bool,
     *     messages: array<int, string>,
     *     elapsed_ms: int,
     *     error?: string,
     * }
     */
    public function run(string $slug): array
    {
        $start = microtime(true);

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

        $all = get_plugins();
        if (! isset($all[$slug])) {
            return $this->fail($slug, 'plugin_not_installed', "No plugin installed at slug '{$slug}'.", $start);
        }

        self::flushActivePluginsCache();
        $activePluginsBefore = array_values(array_filter((array) get_option('active_plugins', []), 'is_string'));
        $beforeVersion = (string) ($all[$slug]['Version'] ?? '');
        $wasActive = is_plugin_active($slug);

        $priorTransient = get_site_transient('update_plugins');
        $priorEntry = (is_object($priorTransient) && isset($priorTransient->response[$slug]))
            ? $priorTransient->response[$slug]
            : null;

        // Force a fresh update check so the upgrader sees the latest available
        // version instead of the cached transient (which may be up to ~12h
        // stale). Cheap on a single targeted call; we deliberately don't do
        // this in the bulk snapshot pull because of api.wordpress.org load.
        if (class_exists(SelfUpdater::class) && $slug === SelfUpdater::PLUGIN_FILE) {
            delete_transient(SelfUpdater::CACHE_KEY);
        }
        wp_update_plugins(['source' => 'clockwork-runner']);

        $updateTransient = get_site_transient('update_plugins');
        $hasUpdate = is_object($updateTransient)
            && isset($updateTransient->response[$slug]);

        // Premium plugins (Elementor Pro, Crocoblock Jet*, PowerPack, Admin
        // Columns Pro, etc.) only hook pre_set_site_transient_update_plugins
        // when WP_ADMIN / DOING_AJAX is true. Calling wp_update_plugins() in
        // REST context above can wipe their entry from the transient. Refresh
        // via admin-ajax loopback or restore the prior entry when needed.
        if (! $hasUpdate || empty(((array) $updateTransient->response[$slug])['package'])) {
            if (class_exists(TransientRefresher::class)) {
                TransientRefresher::forceRefresh();
                $updateTransient = get_site_transient('update_plugins');
                $hasUpdate = is_object($updateTransient)
                    && isset($updateTransient->response[$slug]);
            }

            if ($priorEntry !== null) {
                $currentPackage = $hasUpdate ? (string) (((array) $updateTransient->response[$slug])['package'] ?? '') : '';
                $priorPackage = (string) (((array) $priorEntry)['package'] ?? '');
                if (! $hasUpdate || ($currentPackage === '' && $priorPackage !== '')) {
                    if (! is_object($updateTransient)) {
                        $updateTransient = new \stdClass();
                    }
                    if (! isset($updateTransient->response) || ! is_array($updateTransient->response)) {
                        $updateTransient->response = [];
                    }
                    $updateTransient->response[$slug] = $priorEntry;
                    set_site_transient('update_plugins', $updateTransient);
                    $hasUpdate = true;
                }
            }
        }

        if (! $hasUpdate) {
            // Already up to date — return success with no version change so
            // the UI can render "no-op" rather than treating it as an error.
            return [
                'ok' => true,
                'slug' => $slug,
                'before_version' => $beforeVersion,
                'after_version' => $beforeVersion,
                'was_active' => $wasActive,
                'reactivated' => $wasActive,
                'messages' => ['Plugin is already up to date.'],
                'elapsed_ms' => $this->elapsed($start),
            ];
        }

        if (! WP_Filesystem()) {
            return $this->fail($slug, 'filesystem_init_failed', 'WP_Filesystem could not be initialised — file ownership or permissions issue.', $start);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        $preventDeactivate = null;
        $preserveActivePlugins = null;
        if ($wasActive && function_exists('add_filter')) {
            // Plugin_Upgrader::upgrade() hooks deactivate_plugin_before_upgrade at
            // priority 10 on upgrader_pre_install, which calls deactivate_plugins($slug, true)
            // and writes an active_plugins array missing $slug (with an unset() integer
            // key gap) to wp_options + Redis for the entire 2-6s unpack/hook window.
            // Concurrent frontend requests that boot during that window cache that
            // incomplete array in memory, and plugins with shutdown hooks that rewrite
            // active_plugins (e.g. NextGEN Gallery's C_NextGEN_Bootstrap::fix_loading_order)
            // overwrite the DB with the deactivated state even after Runner finishes.
            // Removing deactivate_plugin_before_upgrade lets Plugin_Upgrader::active_before()
            // use brief .maintenance mode instead (matching bulk_upgrade() / cron behavior).
            $preventDeactivate = static function ($response) use ($upgrader) {
                if (function_exists('remove_filter')) {
                    remove_filter('upgrader_pre_install', [$upgrader, 'deactivate_plugin_before_upgrade'], 10);
                }

                return $response;
            };
            add_filter('upgrader_pre_install', $preventDeactivate, 1, 1);

            $preserveActivePlugins = static function ($newValue) use ($slug, $activePluginsBefore) {
                if (! is_array($newValue)) {
                    return $newValue;
                }
                if (! in_array($slug, $newValue, true)) {
                    return self::restorePluginInOrder($newValue, $activePluginsBefore, $slug);
                }

                return array_values($newValue);
            };
            add_filter('pre_update_option_active_plugins', $preserveActivePlugins, 9999, 1);
        }

        try {
            $result = $upgrader->upgrade($slug);
        } finally {
            if ($preventDeactivate !== null && function_exists('remove_filter')) {
                remove_filter('upgrader_pre_install', $preventDeactivate, 1);
            }
            if ($preserveActivePlugins !== null && function_exists('remove_filter')) {
                remove_filter('pre_update_option_active_plugins', $preserveActivePlugins, 9999);
            }
        }

        $messages = $this->collectMessages($skin);

        if ($result === false || is_wp_error($result) || $skin->get_errors()->has_errors()) {
            $error = is_wp_error($result)
                ? $result->get_error_message()
                : ($skin->get_errors()->has_errors()
                    ? implode('; ', array_map(static fn ($m) => wp_strip_all_tags((string) $m), $skin->get_errors()->get_error_messages()))
                    : 'Plugin_Upgrader::upgrade returned false.');
            return $this->fail($slug, 'upgrade_failed', $error, $start, $messages);
        }

        // After upgrade: re-read the plugin file to get the new version.
        // get_plugins() result is cached for the request — we have to call
        // get_plugin_data() directly against the file to avoid stale data.
        $afterVersion = $beforeVersion;
        $pluginFile = WP_PLUGIN_DIR . '/' . $slug;
        if (file_exists($pluginFile)) {
            $pluginData = get_plugin_data($pluginFile, false, false);
            $afterVersion = (string) ($pluginData['Version'] ?? $beforeVersion);
        }

        self::flushActivePluginsCache();

        // Re-activate if it was active before the upgrade. activate_plugin()
        // returns null on success, WP_Error on failure. Note: activating
        // doesn't re-fire the activation hook by default, which is what we
        // want for an upgrade (the plugin's first-time setup already ran).
        $reactivated = ! $wasActive;
        $reactivationError = null;
        if ($wasActive) {
            $activateResult = activate_plugin($slug, '', false, true);
            if (is_wp_error($activateResult)) {
                $reactivationError = $activateResult->get_error_message();
                $messages[] = 'Re-activation failed: ' . $reactivationError;
                $reactivated = false;
                if (is_plugin_active($slug) && function_exists('deactivate_plugins')) {
                    deactivate_plugins($slug, true);
                }
            } else {
                self::ensureActivePluginOrderPersisted($slug, $activePluginsBefore);
                $reactivated = true;
            }
        }

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[clockwork-updates] upgraded %s %s -> %s (was_active=%s, reactivated=%s)',
                $slug,
                $beforeVersion,
                $afterVersion,
                $wasActive ? 'yes' : 'no',
                $reactivated ? 'yes' : 'no'
            ));
        }

        // A plugin that was active before the upgrade and is not active
        // after it is a failure, full stop — regardless of whether
        // Plugin_Upgrader::upgrade() itself reported success. This is the
        // one case that matters most (the site just lost functionality),
        // and it's also the signal for a corrupted/incomplete file swap:
        // activate_plugin() fails with a WP_Error when the plugin's main
        // file is missing. Reporting ok=true here was letting these sail
        // through the nightly job as "complete" with the failure buried
        // in `messages`, where nothing was watching.
        if ($wasActive && ! $reactivated) {
            return [
                'ok' => false,
                // The file swap itself succeeded even though the overall
                // call failed — distinct from 'ok' so the orchestrator can
                // still run its post-update-verify pass (which checks the
                // whole site's plugin list, not just this slug, and can
                // catch collateral deactivations of OTHER plugins even when
                // this plugin's own reactivation is unrecoverable).
                'upgrade_completed' => true,
                'slug' => $slug,
                'before_version' => $beforeVersion,
                'after_version' => $afterVersion,
                'was_active' => $wasActive,
                'reactivated' => $reactivated,
                'messages' => $messages,
                'elapsed_ms' => $this->elapsed($start),
                'error' => sprintf(
                    'reactivation_failed: files updated %s -> %s but the plugin failed to re-activate (%s). Site is running with this plugin OFF.',
                    $beforeVersion,
                    $afterVersion,
                    $reactivationError ?? 'unknown reason'
                ),
            ];
        }

        return [
            'ok' => true,
            'upgrade_completed' => true,
            'slug' => $slug,
            'before_version' => $beforeVersion,
            'after_version' => $afterVersion,
            'was_active' => $wasActive,
            'reactivated' => $reactivated,
            'messages' => $messages,
            'elapsed_ms' => $this->elapsed($start),
        ];
    }

    /**
     * @param  array<int, string>  $messages
     * @return array{ok: bool, slug: string, before_version: string, after_version: null, was_active: bool, reactivated: bool, messages: array<int, string>, elapsed_ms: int, error: string}
     */
    private function fail(string $slug, string $code, string $message, float $start, array $messages = []): array
    {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("[clockwork-updates] failed {$slug}: {$code} — {$message}");
        }

        return [
            'ok' => false,
            'slug' => $slug,
            'before_version' => '',
            'after_version' => null,
            'was_active' => false,
            'reactivated' => false,
            'messages' => $messages,
            'elapsed_ms' => $this->elapsed($start),
            'error' => "{$code}: {$message}",
        ];
    }

    /**
     * @return array<int, string>
     */
    private function collectMessages(WP_Ajax_Upgrader_Skin $skin): array
    {
        $messages = [];
        if (method_exists($skin, 'get_upgrade_messages')) {
            foreach ((array) $skin->get_upgrade_messages() as $m) {
                $messages[] = (string) wp_strip_all_tags((string) $m);
            }
        }
        if ($skin->get_errors()->has_errors()) {
            foreach ($skin->get_errors()->get_error_messages() as $m) {
                $messages[] = 'Error: ' . wp_strip_all_tags((string) $m);
            }
        }

        return $messages;
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * Flush both `alloptions` and `active_plugins` in the object cache so
     * reads and writes of `active_plugins` reconcile directly with MySQL
     * instead of reading or leaving a split-brain Redis `alloptions` blob.
     */
    public static function flushActivePluginsCache(): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('active_plugins', 'options');
        }
    }

    /**
     * Ensure `$slug` is present in `active_plugins` and restore the pre-update
     * plugin load order (which `activate_plugin()`'s `sort()` otherwise
     * scrambles, triggering shutdown hooks like NextGEN Gallery's
     * `C_NextGEN_Bootstrap::fix_loading_order()`).
     *
     * @param  list<string>  $orderBefore
     * @param  list<string>  $additionalSlugs
     */
    public static function ensureActivePluginOrderPersisted(string $slug, array $orderBefore, array $additionalSlugs = []): void
    {
        self::flushActivePluginsCache();

        $current = array_values(array_filter((array) get_option('active_plugins', []), 'is_string'));
        $ensure = array_values(array_unique(array_merge([$slug], $additionalSlugs)));
        $desired = self::restorePluginInOrder($current, $orderBefore, ...$ensure);

        if ($desired !== $current) {
            update_option('active_plugins', $desired);
        }

        // Also synchronize the test stub array when running under PHPUnit.
        if (isset($GLOBALS['wp_test_active_plugins']) && is_array($GLOBALS['wp_test_active_plugins'])) {
            $GLOBALS['wp_test_active_plugins'] = $desired;
        }

        self::flushActivePluginsCache();
    }

    /**
     * Build a sequentially-indexed `active_plugins` array containing all slugs
     * in `$current` plus `$ensureSlugs`, preserving the relative order of
     * `$orderBefore` and keeping any trailing `nextgen-gallery/*` entry last.
     *
     * @param  array<int|string, mixed>  $current
     * @param  list<string>  $orderBefore
     * @return list<string>
     */
    public static function restorePluginInOrder(array $current, array $orderBefore, string ...$ensureSlugs): array
    {
        $currentStrings = array_values(array_filter($current, 'is_string'));
        $targetSet = array_values(array_unique(array_merge($currentStrings, $ensureSlugs)));
        $targetLookup = array_flip($targetSet);

        $ordered = [];
        $seen = [];
        foreach ($orderBefore as $plugin) {
            if (is_string($plugin) && isset($targetLookup[$plugin]) && ! isset($seen[$plugin])) {
                $ordered[] = $plugin;
                $seen[$plugin] = true;
            }
        }

        $trailingNgg = [];
        while ($ordered !== [] && str_starts_with((string) end($ordered), 'nextgen-gallery/')) {
            array_unshift($trailingNgg, (string) array_pop($ordered));
        }

        $newPlugins = [];
        foreach ($targetSet as $plugin) {
            if (! isset($seen[$plugin])) {
                if (str_starts_with($plugin, 'nextgen-gallery/')) {
                    $trailingNgg[] = $plugin;
                } else {
                    $newPlugins[] = $plugin;
                }
                $seen[$plugin] = true;
            }
        }
        sort($newPlugins);

        if ($newPlugins !== []) {
            // Skip any leading plugins that were intentionally pinned ahead of
            // alphabetical order (e.g. gravityforms/gravityforms.php at index 0).
            $alphaStart = 0;
            $count = count($ordered);
            while ($alphaStart + 1 < $count && strcmp($ordered[$alphaStart], $ordered[$alphaStart + 1]) > 0) {
                $alphaStart++;
            }

            foreach ($newPlugins as $plugin) {
                $inserted = false;
                for ($i = $alphaStart, $n = count($ordered); $i < $n; $i++) {
                    if (strcmp($plugin, $ordered[$i]) < 0) {
                        array_splice($ordered, $i, 0, [$plugin]);
                        $inserted = true;
                        break;
                    }
                }
                if (! $inserted) {
                    $ordered[] = $plugin;
                }
            }
        }

        return array_values(array_merge($ordered, $trailingNgg));
    }
}
