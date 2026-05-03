<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Updates\Runner;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/plugins/update
 *
 * Upgrades a single plugin via WordPress's native Plugin_Upgrader. The
 * Clockwork UI loops over selected slugs client-side rather than passing a
 * batch — keeps each request short, gives the user per-plugin live feedback,
 * and avoids HTTP timeout headaches when one slow plugin holds up a batch.
 *
 * Request body:
 *   { "slug": "akismet/akismet.php" }
 *
 * Response (200, success):
 *   {
 *     "ok": true,
 *     "slug": "akismet/akismet.php",
 *     "before_version": "5.3.7",
 *     "after_version": "5.4.0",
 *     "was_active": true,
 *     "reactivated": true,
 *     "messages": ["Downloading update from ...", "Installing the latest version..."],
 *     "elapsed_ms": 4321
 *   }
 *
 * Response (200, per-plugin failure — transport succeeded, upgrade didn't):
 *   {
 *     "ok": false,
 *     "slug": "akismet/akismet.php",
 *     "error": "upgrade_failed: Could not download package",
 *     "messages": [...]
 *   }
 *
 * HTTP 4xx/5xx responses are reserved for transport-level failures (HMAC,
 * malformed slug, missing body) — the per-plugin upgrade outcome is data,
 * not a transport error, so the UI can handle a partial-success batch.
 */
class PluginUpdateRoute
{
    /**
     * Slug shape: either "directory/file.php" (most plugins) or "single-file.php"
     * (rare — single-file plugins like Hello Dolly). Restricting to this shape
     * blocks any path-traversal or weird input from reaching get_plugins().
     */
    private const SLUG_PATTERN = '~^(?:[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*\.php$~i';

    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/plugins/update', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
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
        if ($slug === '') {
            return new WP_Error('invalid_input', 'slug is required', ['status' => 400]);
        }

        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            return new WP_Error('invalid_input', "slug '{$slug}' has an invalid shape", ['status' => 400]);
        }

        $result = (new Runner())->run($slug);

        return new WP_REST_Response($result);
    }
}
