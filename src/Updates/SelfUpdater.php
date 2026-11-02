<?php

namespace ClockworkCompanion\Updates;

use ClockworkCompanion\Backup\Paths;

/**
 * Self-updater and plugin-lifecycle guard for Clockwork Companion when
 * installed as a standard WordPress plugin (`wp-content/plugins/clockwork-companion/`).
 *
 * Hooks WordPress's native update flow (the `Update URI` plugin header routes
 * the check through `update_plugins_github.com`), so updates show up on
 * Dashboard → Updates, Plugins, and Clockwork Control's plugin-updates drawer
 * like any other plugin and are applied by WordPress's own upgrader.
 *
 * Source of truth is the latest published, non-prerelease GitHub Release. The
 * package URL must be an https://github.com/<repo>/releases/download/ asset;
 * anything else is ignored.
 *
 * Also hides the Deactivate / Delete action links on the WP Admin Plugins
 * screen and keeps the plugin in `active_plugins` unless `WP_CLI` is running
 * or `CLOCKWORK_COMPANION_ALLOW_DEACTIVATE` is defined, preserving the
 * tamper-resistance that Companion previously had as an mu-plugin.
 *
 * Opt out of GitHub update checks with
 * `define('CLOCKWORK_COMPANION_DISABLE_SELF_UPDATE', true)` or the
 * `clockwork_companion_self_update_enabled` filter.
 */
class SelfUpdater
{
    public const REPO = 'Clockwork-Web-Dev-LLC/clockwork-companion';

    public const PLUGIN_FILE = 'clockwork-companion/clockwork-companion.php';

    public const SLUG = 'clockwork-companion';

    public const CACHE_KEY = 'clockwork_companion_latest_release';

    public const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public const FAILURE_TTL = HOUR_IN_SECONDS;

    public static function register(): void
    {
        add_filter('plugin_action_links_'.self::PLUGIN_FILE, [self::class, 'filterActionLinks'], 10, 1);
        add_filter('network_admin_plugin_action_links_'.self::PLUGIN_FILE, [self::class, 'filterActionLinks'], 10, 1);
        add_filter('pre_update_option_active_plugins', [self::class, 'keepPluginActive'], 10, 2);

        self::cleanupLegacyMuPlugin();

        if (! self::enabled()) {
            return;
        }

        add_filter('update_plugins_github.com', [self::class, 'filterUpdate'], 10, 4);
        add_filter('plugins_api', [self::class, 'filterPluginsApi'], 10, 3);
    }

    public static function enabled(): bool
    {
        if (defined('CLOCKWORK_COMPANION_DISABLE_SELF_UPDATE') && CLOCKWORK_COMPANION_DISABLE_SELF_UPDATE) {
            return false;
        }

        return (bool) apply_filters('clockwork_companion_self_update_enabled', true);
    }

    /**
     * Remove 'deactivate' and 'delete' links on the Plugins screen so client
     * administrators cannot accidentally disconnect the site from Clockwork
     * Control after Companion transitions from mu-plugins to plugins.
     *
     * @param  mixed  $actions
     * @return mixed
     */
    public static function filterActionLinks($actions)
    {
        if (! is_array($actions)) {
            return $actions;
        }

        if (defined('CLOCKWORK_COMPANION_ALLOW_DEACTIVATE') && CLOCKWORK_COMPANION_ALLOW_DEACTIVATE) {
            return $actions;
        }

        unset($actions['deactivate'], $actions['delete']);

        return $actions;
    }

    /**
     * Prevent accidental or bulk-action deactivation of Clockwork Companion
     * while the plugin file still exists on disk. WP-CLI and the explicit
     * CLOCKWORK_COMPANION_ALLOW_DEACTIVATE constant are allowed through for
     * emergency troubleshooting.
     *
     * @param  mixed  $newValue
     * @param  mixed  $oldValue
     * @return mixed
     */
    public static function keepPluginActive($newValue, $oldValue)
    {
        if (! is_array($newValue) || ! is_array($oldValue)) {
            return $newValue;
        }

        if (defined('CLOCKWORK_COMPANION_ALLOW_DEACTIVATE') && CLOCKWORK_COMPANION_ALLOW_DEACTIVATE) {
            return $newValue;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return $newValue;
        }

        if (
            in_array(self::PLUGIN_FILE, $oldValue, true)
            && ! in_array(self::PLUGIN_FILE, $newValue, true)
            && defined('CLOCKWORK_COMPANION_FILE')
            && is_file((string) CLOCKWORK_COMPANION_FILE)
        ) {
            $newValue[] = self::PLUGIN_FILE;
            sort($newValue);
        }

        return $newValue;
    }

    /**
     * Once Companion is running from `wp-content/plugins/clockwork-companion`,
     * clean up any leftover `wp-content/mu-plugins/clockwork-companion*` files
     * from an older mu-plugin installation.
     */
    public static function cleanupLegacyMuPlugin(): void
    {
        if (! defined('WPMU_PLUGIN_DIR') || ! defined('CLOCKWORK_COMPANION_DIR')) {
            return;
        }

        $muDir = rtrim((string) WPMU_PLUGIN_DIR, '/\\');
        $currentDir = rtrim((string) CLOCKWORK_COMPANION_DIR, '/\\');

        // Do nothing if Companion is currently running from inside WPMU_PLUGIN_DIR.
        if ($currentDir === $muDir || str_starts_with($currentDir, $muDir.'/') || str_starts_with($currentDir, $muDir.'\\')) {
            return;
        }

        $legacyLoader = $muDir.'/clockwork-companion.php';
        if (is_file($legacyLoader)) {
            @unlink($legacyLoader);
        }

        $legacySubdir = $muDir.'/clockwork-companion';
        if (is_dir($legacySubdir)) {
            Paths::rmrf($legacySubdir);
        }
    }

    /**
     * `update_plugins_{$hostname}` callback.
     *
     * @param  mixed  $update  Existing update data (false when none).
     * @param  array<string, mixed>  $pluginData  Plugin header data.
     * @param  string  $pluginFile  Plugin file relative to the plugins dir.
     * @return mixed
     */
    public static function filterUpdate($update, $pluginData, $pluginFile, $locales = [])
    {
        if ($pluginFile !== self::PLUGIN_FILE) {
            return $update;
        }

        $current = defined('CLOCKWORK_COMPANION_VERSION') ? (string) CLOCKWORK_COMPANION_VERSION : '0';
        $release = self::latestRelease();
        if ($release === null) {
            return $update;
        }

        $info = self::updateFromRelease($release, $current);

        return $info ?? $update;
    }

    /**
     * Build the update array WordPress expects from a release, or null when the
     * release is not newer or has no trustworthy package.
     *
     * @param  array<string, mixed>  $release  Normalized release (see normalizeRelease()).
     * @return array<string, string>|null
     */
    public static function updateFromRelease(array $release, string $current): ?array
    {
        $version = (string) ($release['version'] ?? '');
        $package = (string) ($release['package'] ?? '');

        if ($version === '' || ! version_compare($version, $current, '>')) {
            return null;
        }
        if (! self::isTrustedPackageUrl($package)) {
            return null;
        }

        return [
            'id' => 'https://github.com/'.self::REPO,
            'slug' => self::SLUG,
            'plugin' => self::PLUGIN_FILE,
            'version' => $version,
            'url' => 'https://github.com/'.self::REPO.'/releases/tag/v'.$version,
            'package' => $package,
        ];
    }

    /**
     * `plugins_api` callback — powers the "View details" modal.
     *
     * @param  mixed  $result
     * @param  string  $action
     * @param  mixed  $args
     * @return mixed
     */
    public static function filterPluginsApi($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ! is_object($args) || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $release = self::latestRelease();
        if ($release === null) {
            return $result;
        }

        $body = (string) ($release['body'] ?? '');

        return (object) [
            'name' => 'Clockwork Companion',
            'slug' => self::SLUG,
            'version' => (string) $release['version'],
            'author' => '<a href="https://clockworkcontrol.com">Clockwork Web Dev, LLC</a>',
            'homepage' => 'https://github.com/'.self::REPO,
            'download_link' => self::isTrustedPackageUrl((string) $release['package']) ? (string) $release['package'] : '',
            'sections' => [
                'description' => 'Connects your WordPress site to Clockwork Control for fleet-wide monitoring, backups, updates, and security telemetry.',
                'changelog' => $body !== '' ? nl2br(esc_html($body)) : 'See the GitHub release notes.',
            ],
        ];
    }

    /** Only HTTPS release-asset downloads from this plugin's own repository are accepted. */
    public static function isTrustedPackageUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'github.com'
            && isset($parts['path'])
            && str_starts_with($parts['path'], '/'.self::REPO.'/releases/download/')
            && str_ends_with($parts['path'], '.zip')
            && ! isset($parts['user'])
            && ! isset($parts['port']);
    }

    /**
     * Reduce a GitHub "latest release" API payload to what we use.
     *
     * @param  array<string, mixed>  $payload
     * @return array{version: string, package: string, body: string}|null
     */
    public static function normalizeRelease(array $payload): ?array
    {
        if (! empty($payload['draft']) || ! empty($payload['prerelease'])) {
            return null;
        }

        $version = ltrim((string) ($payload['tag_name'] ?? ''), 'vV');
        if ($version === '' || ! preg_match('/^\d+(\.\d+)*([-+][0-9A-Za-z.-]+)?$/', $version)) {
            return null;
        }

        $package = '';
        foreach ((array) ($payload['assets'] ?? []) as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            if ($name === 'clockwork-companion.zip' || $name === 'clockwork-companion-'.$version.'.zip') {
                $package = (string) ($asset['browser_download_url'] ?? '');
                break;
            }
        }

        return [
            'version' => $version,
            'package' => $package,
            'body' => (string) ($payload['body'] ?? ''),
        ];
    }

    /**
     * @return array{version: string, package: string, body: string}|null
     */
    public static function latestRelease(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached === [] ? null : $cached;
        }

        $release = self::fetchLatestRelease();
        set_transient(self::CACHE_KEY, $release ?? [], $release === null ? self::FAILURE_TTL : self::CACHE_TTL);

        return $release;
    }

    /**
     * @return array{version: string, package: string, body: string}|null
     */
    private static function fetchLatestRelease(): ?array
    {
        $response = wp_remote_get('https://api.github.com/repos/'.self::REPO.'/releases/latest', [
            'timeout' => 8,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'clockwork-companion/'.(defined('CLOCKWORK_COMPANION_VERSION') ? CLOCKWORK_COMPANION_VERSION : 'dev'),
            ],
        ]);

        if (is_wp_error($response) || (int) ($response['response']['code'] ?? 0) !== 200) {
            return null;
        }

        $payload = json_decode((string) ($response['body'] ?? ''), true);

        return is_array($payload) ? self::normalizeRelease($payload) : null;
    }
}
