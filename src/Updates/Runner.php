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
 *      running with the plugin off — the dangerous failure mode.
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

        $beforeVersion = (string) ($all[$slug]['Version'] ?? '');
        $wasActive = is_plugin_active($slug);

        // Force a fresh update check so the upgrader sees the latest available
        // version instead of the cached transient (which may be up to ~12h
        // stale). Cheap on a single targeted call; we deliberately don't do
        // this in the bulk snapshot pull because of api.wordpress.org load.
        wp_update_plugins();

        $updateTransient = get_site_transient('update_plugins');
        $hasUpdate = is_object($updateTransient)
            && isset($updateTransient->response[$slug]);

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
        $result = $upgrader->upgrade($slug);

        $messages = $this->collectMessages($skin);

        if ($result === false || is_wp_error($result)) {
            $error = is_wp_error($result)
                ? $result->get_error_message()
                : 'Plugin_Upgrader::upgrade returned false.';
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

        // Re-activate if it was active before the upgrade. activate_plugin()
        // returns null on success, WP_Error on failure. Note: activating
        // doesn't re-fire the activation hook by default, which is what we
        // want for an upgrade (the plugin's first-time setup already ran).
        $reactivated = ! $wasActive;
        if ($wasActive) {
            $activateResult = activate_plugin($slug, '', false, true);
            if (is_wp_error($activateResult)) {
                $messages[] = 'Re-activation failed: ' . $activateResult->get_error_message();
                $reactivated = false;
            } else {
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

        return [
            'ok' => true,
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
}
