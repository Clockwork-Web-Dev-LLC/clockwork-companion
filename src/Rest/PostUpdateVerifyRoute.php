<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/post-update-verify
 *
 * Called by Clockwork immediately after a successful plugin, theme, or core
 * update to verify that the site's active state matches what it was before
 * the update. Any discrepancies are repaired in place.
 *
 * WordPress can silently break site state during an update:
 *   - A plugin update that triggers a PHP fatal deactivates the plugin (or
 *     sometimes OTHER plugins via deactivation hooks)
 *   - A core update deactivates plugins it considers incompatible with the
 *     new version
 *   - A theme update's brief filesystem-replacement window can trigger
 *     validate_current_theme() which silently switches to the default theme
 *     (multisite already handled in ThemeUpdateRoute; this covers single-site)
 *
 * Request body:
 *   {
 *     "active_plugins": ["plugin-a/plugin-a.php", "plugin-b/plugin-b.php"],
 *     "stylesheet": "my-theme",
 *     "template": "my-parent-theme"
 *   }
 *
 * Response (200):
 *   {
 *     "ok": true,
 *     "repairs": [
 *       { "type": "plugin_reactivated", "slug": "plugin-a/plugin-a.php", "detail": "..." },
 *       { "type": "theme_restored", "slug": "my-theme", "detail": "was: twentytwentyfive" }
 *     ]
 *   }
 *
 * An empty `repairs` array means everything was already in order.
 */
class PostUpdateVerifyRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/post-update-verify', [
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

        $expectedPlugins    = isset($body['active_plugins']) && is_array($body['active_plugins'])
            ? array_values(array_filter($body['active_plugins'], 'is_string'))
            : [];
        $expectedStylesheet = isset($body['stylesheet']) ? (string) $body['stylesheet'] : '';
        $expectedTemplate   = isset($body['template']) ? (string) $body['template'] : $expectedStylesheet;

        $repairs = [];

        $repairs = array_merge($repairs, $this->verifyPlugins($expectedPlugins));
        $repairs = array_merge($repairs, $this->verifyTheme($expectedStylesheet, $expectedTemplate));

        return new WP_REST_Response([
            'ok'      => true,
            'repairs' => $repairs,
        ]);
    }

    /**
     * Re-activate any plugin from the expected set that is now inactive.
     * Skips plugins that are no longer installed (they may have been legitimately
     * removed as part of the update process — don't re-activate what's gone).
     *
     * @param  list<string>  $expectedSlugs
     * @return list<array{type: string, slug: string, detail: string}>
     */
    private function verifyPlugins(array $expectedSlugs): array
    {
        if ($expectedSlugs === []) {
            return [];
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $currentActive = (array) get_option('active_plugins', []);
        $installed     = array_keys(get_plugins());
        $repairs       = [];

        foreach ($expectedSlugs as $slug) {
            if (in_array($slug, $currentActive, true)) {
                continue; // still active — nothing to do
            }

            if (! in_array($slug, $installed, true)) {
                continue; // no longer installed — skip
            }

            $result = activate_plugin($slug, '', false, true);

            if (is_wp_error($result)) {
                $repairs[] = [
                    'type'   => 'plugin_reactivate_failed',
                    'slug'   => $slug,
                    'detail' => $result->get_error_message(),
                ];
            } else {
                $repairs[] = [
                    'type'   => 'plugin_reactivated',
                    'slug'   => $slug,
                    'detail' => 'was inactive after update; re-activated successfully',
                ];
            }
        }

        return $repairs;
    }

    /**
     * Restore the active theme if WordPress switched it away from the expected
     * stylesheet during the update's filesystem-replacement window.
     *
     * @return list<array{type: string, slug: string, detail: string}>
     */
    private function verifyTheme(string $expectedStylesheet, string $expectedTemplate): array
    {
        if ($expectedStylesheet === '') {
            return [];
        }

        $currentStylesheet = (string) get_stylesheet();

        if ($currentStylesheet === $expectedStylesheet) {
            return []; // theme is correct
        }

        // Only restore if the expected theme is still installed.
        $theme = wp_get_theme($expectedStylesheet);
        if (! $theme->exists()) {
            return []; // expected theme is gone — can't restore
        }

        switch_theme($expectedStylesheet);

        return [[
            'type'   => 'theme_restored',
            'slug'   => $expectedStylesheet,
            'detail' => "was: {$currentStylesheet}",
        ]];
    }
}
