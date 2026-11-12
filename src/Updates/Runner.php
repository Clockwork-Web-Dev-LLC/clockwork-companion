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
 *   5. Run Plugin_Upgrader::upgrade(). For an active plugin the site is in
 *      core maintenance mode from just before the old files are removed
 *      until the new version has been checked (step 6).
 *   6. Active plugin only: load the home page through core's fatal-error
 *      scrape. A fatal rolls the plugin back to the previous version
 *      (core's temporary backup) — or, where that is not possible,
 *      deactivates it — and the call reports ok=false.
 *   6b. Capture after_version.
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
    /** Builds the upgrader for a skin; null means a real Plugin_Upgrader. Test seam. */
    private ?\Closure $upgraderFactory;

    public function __construct(?\Closure $upgraderFactory = null)
    {
        $this->upgraderFactory = $upgraderFactory;
    }

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
        $upgrader = $this->upgraderFactory !== null ? ($this->upgraderFactory)($skin) : new Plugin_Upgrader($skin);

        $preventDeactivate = null;
        $preserveActivePlugins = null;
        $enterMaintenance = null;
        $maintenanceOn = false;
        if ($wasActive && function_exists('add_filter')) {
            // Plugin_Upgrader::upgrade() hooks deactivate_plugin_before_upgrade at
            // priority 10 on upgrader_pre_install, which calls deactivate_plugins($slug, true)
            // and writes an active_plugins array missing $slug (with an unset() integer
            // key gap) to wp_options + Redis for the entire 2-6s unpack/hook window.
            // Concurrent frontend requests that boot during that window cache that
            // incomplete array in memory, and plugins with shutdown hooks that rewrite
            // active_plugins (e.g. NextGEN Gallery's C_NextGEN_Bootstrap::fix_loading_order)
            // overwrite the DB with the deactivated state even after Runner finishes.
            // So the plugin stays active through the swap, and the site goes into
            // maintenance mode instead (below): core's own active_before() only does
            // that for cron-driven updates, and without either guard a concurrent
            // request could load a half-removed plugin and fatal.
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

            // Enter maintenance only once the package is downloaded and
            // unpacked (upgrader_pre_install runs right before the old
            // directory is removed), so the downtime covers just the swap
            // and the load check.
            $enterMaintenance = static function ($response) use ($upgrader, &$maintenanceOn) {
                if (! is_wp_error($response) && ! $maintenanceOn) {
                    $upgrader->maintenance_mode(true);
                    $maintenanceOn = true;
                }

                return $response;
            };
            add_filter('upgrader_pre_install', $enterMaintenance, 11, 1);
        }

        $loadCheck = null;
        $rollback = null;
        try {
            $result = $upgrader->upgrade($slug);

            // Plugin_Upgrader::upgrade() leaves an active plugin active
            // (it was never deactivated), so activate_plugin() below is a
            // no-op and cannot tell us the new code loads. Ask the site.
            if ($wasActive && $result !== false && ! is_wp_error($result) && ! $skin->get_errors()->has_errors()) {
                $loadCheck = self::scrapeForFatal();
                if ($loadCheck['status'] === 'fatal') {
                    $rollback = $this->rollBack($upgrader, $slug);
                }
            }
        } finally {
            if ($preventDeactivate !== null && function_exists('remove_filter')) {
                remove_filter('upgrader_pre_install', $preventDeactivate, 1);
            }
            if ($preserveActivePlugins !== null && function_exists('remove_filter')) {
                remove_filter('pre_update_option_active_plugins', $preserveActivePlugins, 9999);
            }
            if ($enterMaintenance !== null && function_exists('remove_filter')) {
                remove_filter('upgrader_pre_install', $enterMaintenance, 11);
            }
            if ($maintenanceOn) {
                $upgrader->maintenance_mode(false);
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

        if ($loadCheck !== null && $loadCheck['status'] === 'fatal') {
            return $this->fatalResult($slug, $beforeVersion, $wasActive, (string) $loadCheck['detail'], (array) $rollback, $messages, $start);
        }
        if ($loadCheck !== null && $loadCheck['status'] === 'unverified') {
            // Loopback blocked or unreadable: nothing proves a fatal, so the
            // update stands, but say that it was not checked.
            $messages[] = 'Load check skipped: ' . $loadCheck['detail'];
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
            // Only meaningful for a plugin that was active: true when the
            // site was loaded with the new version and did not fatal.
            'load_verified' => $loadCheck !== null && $loadCheck['status'] === 'ok',
            'messages' => $messages,
            'elapsed_ms' => $this->elapsed($start),
        ];
    }

    /**
     * Load the home page through core's fatal-error scrape (the mechanism
     * WP_Automatic_Updater uses after background plugin updates and the
     * plugin editor uses after a save): wp-settings.php reports the last
     * fatal error between two markers when the request carries a scrape key
     * matching a short-lived transient. While the site is in maintenance mode
     * the key must be derived from the .maintenance timestamp — that is what
     * lets this one request through wp_is_maintenance_mode().
     *
     * Only an explicit fatal in the scrape output counts as a failure. A
     * blocked or unreadable loopback is 'unverified', not 'fatal': many hosts
     * block loopback requests, and rolling back every update there would be
     * worse than not checking.
     *
     * @return array{status: string, detail: string} status is ok|fatal|unverified
     */
    public static function scrapeForFatal(): array
    {
        $upgrading = self::maintenanceTimestamp();
        if ($upgrading !== null) {
            $key = md5((string) $upgrading);
            $nonce = (string) $upgrading;
        } else {
            $key = bin2hex(random_bytes(16));
            $nonce = bin2hex(random_bytes(16));
        }

        $transient = 'scrape_key_' . $key;
        set_transient($transient, $nonce, 60);

        $url = add_query_arg(['wp_scrape_key' => $key, 'wp_scrape_nonce' => $nonce], home_url('/'));
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Cache-Control' => 'no-cache'],
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
            'sslverify' => (bool) apply_filters('https_local_ssl_verify', false, $url),
        ]);

        delete_transient($transient);

        if (is_wp_error($response)) {
            return ['status' => 'unverified', 'detail' => 'loopback request failed: ' . $response->get_error_message()];
        }

        $body = (string) (is_array($response) ? ($response['body'] ?? '') : '');
        $start = "###### wp_scraping_result_start:{$key} ######";
        $end = "###### wp_scraping_result_end:{$key} ######";
        $from = strpos($body, $start);
        $to = $from === false ? false : strpos($body, $end, $from);
        if ($from === false || $to === false) {
            $code = is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;

            return ['status' => 'unverified', 'detail' => "no scrape result in the response (HTTP {$code})"];
        }

        $result = json_decode(trim(substr($body, $from + strlen($start), $to - $from - strlen($start))), true);
        if (is_array($result) && isset($result['type'])) {
            return [
                'status' => 'fatal',
                'detail' => sprintf(
                    '%s in %s:%d',
                    (string) ($result['message'] ?? 'fatal error'),
                    (string) ($result['file'] ?? '?'),
                    (int) ($result['line'] ?? 0)
                ),
            ];
        }

        return ['status' => 'ok', 'detail' => ''];
    }

    /** The $upgrading timestamp from ABSPATH/.maintenance, read without executing the file. */
    private static function maintenanceTimestamp(): ?int
    {
        $file = ABSPATH . '.maintenance';
        if (! is_file($file)) {
            return null;
        }

        $contents = (string) @file_get_contents($file);

        return preg_match('/\$upgrading\s*=\s*(\d+)/', $contents, $m) ? (int) $m[1] : null;
    }

    /**
     * Put the previous version back from core's temporary backup (made by
     * Plugin_Upgrader::upgrade() since WordPress 6.3 and kept until shutdown).
     * restore_temp_backup() only takes the backup list from WordPress 6.6;
     * where it cannot, or the restore fails, the plugin is deactivated so the
     * site stops fataling.
     *
     * @return array{rolled_back: bool, deactivated: bool, detail: string}
     */
    private function rollBack(object $upgrader, string $slug): array
    {
        $dir = dirname($slug);
        $canRestore = $dir !== '.'
            && method_exists($upgrader, 'restore_temp_backup')
            && (new \ReflectionMethod($upgrader, 'restore_temp_backup'))->getNumberOfParameters() > 0;

        if ($canRestore) {
            $restored = $upgrader->restore_temp_backup([
                ['dir' => 'plugins', 'slug' => $dir, 'src' => WP_PLUGIN_DIR],
            ]);
            if ($restored === true) {
                if (function_exists('wp_clean_plugins_cache')) {
                    wp_clean_plugins_cache(false);
                }

                return ['rolled_back' => true, 'deactivated' => false, 'detail' => 'previous version restored'];
            }
            $detail = is_wp_error($restored) ? $restored->get_error_message() : 'restore failed';
        } else {
            $detail = 'no restorable backup on this WordPress version';
        }

        if (function_exists('deactivate_plugins')) {
            deactivate_plugins($slug, true);
        }

        return ['rolled_back' => false, 'deactivated' => true, 'detail' => $detail . '; plugin deactivated'];
    }

    /**
     * @param  array{rolled_back?: bool, deactivated?: bool, detail?: string}  $rollback
     * @param  array<int, string>  $messages
     * @return array<string, mixed>
     */
    private function fatalResult(string $slug, string $beforeVersion, bool $wasActive, string $fatal, array $rollback, array $messages, float $start): array
    {
        $rolledBack = ! empty($rollback['rolled_back']);
        $messages[] = 'Load check failed: ' . $fatal;
        $messages[] = 'Recovery: ' . (string) ($rollback['detail'] ?? 'none');

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[clockwork-updates] {$slug} fataled after update: {$fatal}");
        }

        return [
            'ok' => false,
            // Rolled back: the files are the old version again, so there is
            // no completed update to verify. Deactivated instead: the new
            // files are in place, the plugin is just off.
            'upgrade_completed' => ! $rolledBack,
            'slug' => $slug,
            'before_version' => $beforeVersion,
            'after_version' => $rolledBack ? $beforeVersion : null,
            'was_active' => $wasActive,
            'reactivated' => $rolledBack,
            'rolled_back' => $rolledBack,
            'load_verified' => false,
            'messages' => $messages,
            'elapsed_ms' => $this->elapsed($start),
            'error' => sprintf(
                'load_failed: the updated plugin caused a fatal error (%s). %s.',
                $fatal,
                $rolledBack ? 'The previous version was restored' : 'The plugin was deactivated'
            ),
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
