<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Admin\Pages\UpdateCoveragePage;
use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/update-exceptions
 *
 * Inbound endpoint — Clockwork Control pushes the active list of auto-paused
 * plugin/theme update exceptions here when consecutive failures cross the
 * threshold, on operator resume, and during daily catch-up.
 *
 * Last-write-wins: empty items list clears previous exceptions.
 */
class UpdateExceptionsRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/update-exceptions', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'invalid_body', 'message' => 'Body must be a JSON object'],
                400
            );
        }

        $rawItems = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
        $items = [];

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = [
                'kind' => isset($item['kind']) ? (string) $item['kind'] : 'plugin',
                'slug' => isset($item['slug']) ? (string) $item['slug'] : '',
                'name' => isset($item['name']) ? (string) $item['name'] : '',
                'stopped_at' => isset($item['stopped_at']) ? (string) $item['stopped_at'] : '',
                'failure_count' => isset($item['failure_count']) ? (int) $item['failure_count'] : 0,
                'from_version' => isset($item['from_version']) ? (string) $item['from_version'] : '?',
                'attempted_version' => isset($item['attempted_version']) ? (string) $item['attempted_version'] : '?',
                'reason_public' => isset($item['reason_public']) ? (string) $item['reason_public'] : '',
                'status' => isset($item['status']) ? (string) $item['status'] : 'paused',
            ];
        }

        $stored = [
            'generated_at' => isset($payload['generated_at']) ? (string) $payload['generated_at'] : gmdate('c'),
            'site_domain' => isset($payload['site_domain']) ? (string) $payload['site_domain'] : '',
            'items' => $items,
        ];

        update_option(UpdateCoveragePage::OPTION, $stored, false);

        return new WP_REST_Response(['ok' => true]);
    }
}
