<?php

namespace ClockworkCompanion\Rest;

use Automatic_Upgrader_Skin;
use ClockworkCompanion\Auth\HmacVerifier;
use Language_Pack_Upgrader;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork-companion/v1/translations/update
 *
 * Upgrades all pending WordPress translation packages (core, plugins, themes)
 * via Language_Pack_Upgrader::bulk_upgrade(). WordPress handles language
 * packs as a unified batch rather than per-slug.
 *
 * Response (200, success):
 *   {
 *     "ok": true,
 *     "updated_count": 2,
 *     "messages": ["Translation updated successfully."],
 *     "elapsed_ms": 1240
 *   }
 *
 * Response (200, failure):
 *   {
 *     "ok": false,
 *     "updated_count": 0,
 *     "error": "upgrade_failed: ...",
 *     "messages": [],
 *     "elapsed_ms": 320
 *   }
 */
class TranslationsUpdateRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/translations/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->run());
    }

    /**
     * @return array<string, mixed>
     */
    private function run(): array
    {
        $start = microtime(true);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
        require_once ABSPATH . 'wp-includes/update.php';

        if (! function_exists('wp_get_translation_updates')) {
            return $this->fail('unsupported_environment', 'wp_get_translation_updates is not available.', $start);
        }

        $pending = wp_get_translation_updates();
        if (empty($pending)) {
            return [
                'ok'            => true,
                'updated_count' => 0,
                'messages'      => ['Translations are already up to date.'],
                'elapsed_ms'    => $this->elapsed($start),
            ];
        }

        if (! WP_Filesystem()) {
            return $this->fail('filesystem_init_failed', 'WP_Filesystem could not be initialised.', $start);
        }

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Language_Pack_Upgrader($skin);
        $result   = $upgrader->bulk_upgrade();

        $messages = $this->collectMessages($skin);

        if ($result === false || is_wp_error($result)) {
            $error = is_wp_error($result) ? $result->get_error_message() : 'Language_Pack_Upgrader::bulk_upgrade returned false.';

            return $this->fail('upgrade_failed', $error, $start, $messages);
        }

        $updatedCount = 0;
        if (is_array($result)) {
            foreach ($result as $entry) {
                if ($entry && ! is_wp_error($entry)) {
                    $updatedCount++;
                }
            }
        } elseif ($result === true) {
            $updatedCount = count((array) $pending);
        }

        $elapsedMs = $this->elapsed($start);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf(
                '[clockwork-translations] updated %d translation packages in %dms',
                $updatedCount,
                $elapsedMs
            ));
        }

        return [
            'ok'            => true,
            'updated_count' => $updatedCount,
            'messages'      => $messages,
            'elapsed_ms'    => $elapsedMs,
        ];
    }

    /** @param array<int, string> $messages */
    private function fail(string $code, string $message, float $start, array $messages = []): array
    {
        return [
            'ok'            => false,
            'updated_count' => 0,
            'messages'      => $messages,
            'elapsed_ms'    => $this->elapsed($start),
            'error'         => "{$code}: {$message}",
        ];
    }

    private function collectMessages(Automatic_Upgrader_Skin $skin): array
    {
        $messages = [];
        if (method_exists($skin, 'get_upgrade_messages')) {
            foreach ((array) $skin->get_upgrade_messages() as $m) {
                $messages[] = (string) wp_strip_all_tags((string) $m);
            }
        }
        if ($skin->get_errors() && method_exists($skin->get_errors(), 'has_errors') && $skin->get_errors()->has_errors()) {
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
