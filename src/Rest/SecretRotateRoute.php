<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Auth\Secret;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/secret/rotate
 *
 * Rotates the per-site HMAC secret. Clockwork signs this call with the OLD
 * secret; Companion verifies, generates a NEW secret, persists it, and
 * returns the new value so Clockwork can update sites.companion_secret.
 *
 * Race risk: if Clockwork loses the response mid-flight, Clockwork's stored
 * secret is stale and subsequent calls fail. Recovery is a Companion
 * reinstall (`clockwork:install-companion <site>`), which generates a fresh
 * secret end-to-end.
 *
 * Constant-mode sites: if `CLOCKWORK_COMPANION_SECRET` is defined in
 * wp-config.php, the secret is owned by the site operator. We refuse to
 * rotate and return a structured 409 so the operator UI can explain the
 * situation rather than silently no-op.
 */
class SecretRotateRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/secret/rotate', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! Secret::isManaged()) {
            return new WP_Error(
                'secret_pinned',
                'Secret is pinned via wp-config.php constant — rotate it there manually.',
                ['status' => 409]
            );
        }

        try {
            $newSecret = Secret::rotate();
        } catch (Throwable $e) {
            return new WP_Error('rotate_failed', $e->getMessage(), ['status' => 500]);
        }

        return new WP_REST_Response([
            'ok' => true,
            'secret' => $newSecret,
            'rotated_at' => gmdate('c'),
        ]);
    }
}
