<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use Theme_Upgrader;
use WP_Ajax_Upgrader_Skin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/themes/update
 *
 * Upgrades a single theme via WordPress's native Theme_Upgrader. Mirrors
 * PluginUpdateRoute's shape so the Clockwork UI can reuse the same
 * update-batch loop for both kinds.
 *
 * On WordPress multisite, Theme_Upgrader replaces the theme directory
 * network-wide. During the brief window when the old folder is gone and the
 * new one is not yet in place, WordPress's validate_current_theme() can fire
 * (on the next REST request or wp-cron tick) and silently switch any sub-site
 * whose stylesheet/template option matched the slug to the default Twenty
 * theme — persisting that wrong assignment to the database. To prevent this,
 * we record which sub-sites use the slug before the upgrade, then after a
 * successful upgrade we switch into each of those sub-sites and restore the
 * theme if WordPress switched it away.
 *
 * Request body:
 *   { "slug": "twentytwentyfive" }
 *
 * Response (200, success):
 *   {
 *     "ok": true,
 *     "slug": "twentytwentyfive",
 *     "before_version": "1.2",
 *     "after_version": "1.3",
 *     "messages": ["Downloading update...", "Unpacking the update..."],
 *     "elapsed_ms": 3200,
 *     "subsites_repaired": 0
 *   }
 */
class ThemeUpdateRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/themes/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            $body = [];
        }

        $slug = isset($body['slug']) ? (string) $body['slug'] : '';
        if ($slug === '' || ! preg_match('~^[a-z0-9][a-z0-9._-]*$~i', $slug)) {
            return new WP_Error('invalid_input', 'slug is required and must be a valid theme directory name', ['status' => 400]);
        }

        return new WP_REST_Response($this->run($slug));
    }

    /**
     * @return array<string, mixed>
     */
    private function run(string $slug): array
    {
        $start = microtime(true);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

        $theme = wp_get_theme($slug);
        if (! $theme->exists()) {
            return $this->fail($slug, 'theme_not_installed', "No theme installed with slug '{$slug}'.", $start);
        }

        $beforeVersion = (string) $theme->get('Version');

        // Force fresh update check.
        wp_update_themes();

        $transient = get_site_transient('update_themes');
        $hasUpdate = is_object($transient) && isset($transient->response[$slug]);

        if (! $hasUpdate) {
            return [
                'ok'               => true,
                'slug'             => $slug,
                'before_version'   => $beforeVersion,
                'after_version'    => $beforeVersion,
                'messages'         => ['Theme is already up to date.'],
                'elapsed_ms'       => $this->elapsed($start),
                'subsites_repaired' => 0,
            ];
        }

        if (! WP_Filesystem()) {
            return $this->fail($slug, 'filesystem_init_failed', 'WP_Filesystem could not be initialised.', $start);
        }

        // On multisite: record which sub-sites use this slug as their active
        // theme (stylesheet) or parent theme (template) before we touch the
        // filesystem. Theme_Upgrader removes the old directory before placing
        // the new one; any request that hits validate_current_theme() in that
        // window will silently fall back to the default theme and persist
        // that wrong value to the sub-site's options table.
        $affectedBlogIds = $this->multisiteAffectedBlogs($slug);

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $result   = $upgrader->upgrade($slug);

        $messages = $this->collectMessages($skin);

        if ($result === false || is_wp_error($result)) {
            $error = is_wp_error($result) ? $result->get_error_message() : 'Theme_Upgrader::upgrade returned false.';

            return $this->fail($slug, 'upgrade_failed', $error, $start, $messages);
        }

        // Re-read after_version from the theme's style.css (cached headers may be stale).
        $afterTheme   = wp_get_theme($slug);
        $afterVersion = (string) $afterTheme->get('Version') ?: $beforeVersion;

        // Post-upgrade: restore any sub-sites where WordPress silently switched
        // to a fallback theme during the filesystem replacement window.
        $subsitesRepaired = $this->multisiteRepairThemes($slug, $affectedBlogIds);

        return [
            'ok'               => true,
            'slug'             => $slug,
            'before_version'   => $beforeVersion,
            'after_version'    => $afterVersion,
            'messages'         => $messages,
            'elapsed_ms'       => $this->elapsed($start),
            'subsites_repaired' => $subsitesRepaired,
        ];
    }

    /**
     * On multisite: return the blog IDs of every sub-site whose active theme
     * (stylesheet) or parent theme (template) matches $slug. On single-site
     * or when there are no matching sub-sites, returns [].
     *
     * @return list<int>
     */
    private function multisiteAffectedBlogs(string $slug): array
    {
        if (! is_multisite()) {
            return [];
        }

        $affected = [];

        // get_sites() with number=0 returns all sites. On very large networks
        // this could be slow, but theme updates are infrequent operator actions
        // and the correctness guarantee is worth the extra query.
        $blogs = get_sites(['number' => 0, 'fields' => 'ids', 'deleted' => 0, 'archived' => 0]);

        foreach ($blogs as $blogId) {
            switch_to_blog((int) $blogId);
            $stylesheet = (string) get_option('stylesheet', '');
            $template   = (string) get_option('template', '');
            restore_current_blog();

            if ($stylesheet === $slug || $template === $slug) {
                $affected[] = (int) $blogId;
            }
        }

        return $affected;
    }

    /**
     * After a successful upgrade, switch into each affected sub-site and
     * check whether WordPress silently changed its active theme. If it did,
     * restore the intended theme via switch_theme().
     *
     * Returns the count of sub-sites that were repaired.
     *
     * @param list<int> $blogIds
     */
    private function multisiteRepairThemes(string $slug, array $blogIds): int
    {
        if ($blogIds === []) {
            return 0;
        }

        // Clear the theme cache so wp_get_theme() and get_option('stylesheet')
        // reflect the freshly-installed files, not stale transients.
        wp_clean_themes_cache();

        $repaired = 0;

        foreach ($blogIds as $blogId) {
            switch_to_blog($blogId);

            $currentStylesheet = (string) get_option('stylesheet', '');

            if ($currentStylesheet !== $slug) {
                // WordPress switched this sub-site to a fallback — restore it.
                switch_theme($slug);
                $repaired++;
            }

            restore_current_blog();
        }

        return $repaired;
    }

    /** @param array<int, string> $messages */
    private function fail(string $slug, string $code, string $message, float $start, array $messages = []): array
    {
        return [
            'ok'               => false,
            'slug'             => $slug,
            'before_version'   => '',
            'after_version'    => null,
            'messages'         => $messages,
            'elapsed_ms'       => $this->elapsed($start),
            'error'            => "{$code}: {$message}",
            'subsites_repaired' => 0,
        ];
    }

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
