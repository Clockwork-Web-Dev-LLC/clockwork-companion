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
 *     "elapsed_ms": 3200
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
                'ok'             => true,
                'slug'           => $slug,
                'before_version' => $beforeVersion,
                'after_version'  => $beforeVersion,
                'messages'       => ['Theme is already up to date.'],
                'elapsed_ms'     => $this->elapsed($start),
            ];
        }

        if (! WP_Filesystem()) {
            return $this->fail($slug, 'filesystem_init_failed', 'WP_Filesystem could not be initialised.', $start);
        }

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

        return [
            'ok'             => true,
            'slug'           => $slug,
            'before_version' => $beforeVersion,
            'after_version'  => $afterVersion,
            'messages'       => $messages,
            'elapsed_ms'     => $this->elapsed($start),
        ];
    }

    /** @param array<int, string> $messages */
    private function fail(string $slug, string $code, string $message, float $start, array $messages = []): array
    {
        return [
            'ok'             => false,
            'slug'           => $slug,
            'before_version' => '',
            'after_version'  => null,
            'messages'       => $messages,
            'elapsed_ms'     => $this->elapsed($start),
            'error'          => "{$code}: {$message}",
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
