<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Auth\RemoteActionAudit;
use ClockworkCompanion\Auth\Secret;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/secret/rotate
 *
 * Rotates the per-site HMAC secret. Clockwork signs this call with the OLD
 * secret; the plugin verifies, generates a NEW secret, stores it as pending
 * and returns it so Clockwork can update sites.companion_secret. The old
 * secret keeps working until Clockwork's first request signed with the new
 * one, which promotes it (see Auth\Secret). A lost response therefore
 * leaves the site reachable with the old secret, and the next rotate
 * replaces the unconfirmed one.
 *
 * The secret is only returned once it is stored; a failed write is a 500
 * and the current secret is left as it was.
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
            RemoteActionAudit::record('connection_key_rotation', null, 'Connection key rotation failed.', [], false, $e->getMessage());

            return new WP_Error('rotate_failed', $e->getMessage(), ['status' => 500]);
        }

        // Never the secret itself — only that a rotation was issued.
        RemoteActionAudit::record(
            'connection_key_rotation',
            null,
            'New connection key issued; the previous key stays valid until Clockwork Control uses the new one.'
        );

        return new WP_REST_Response([
            'ok' => true,
            'secret' => $newSecret,
            'rotated_at' => gmdate('c'),
            // Additive: the old secret stays valid until the new one is used.
            'pending' => true,
        ]);
    }
}
