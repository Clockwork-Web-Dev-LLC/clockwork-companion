<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/wordfence-blocks
 *
 * Returns active Wordfence IP blocks for this site.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "blocks": [
 *       {
 *         "ip": "1.2.3.4",
 *         "expires_at": "2026-05-02T23:50:00+00:00" | null,
 *         "source_table": "wp_wfBlocks7",
 *         "reason": "..." | null,
 *         "type": "brute" | null
 *       },
 *       ...
 *     ]
 *   }
 *
 * Mirrors the shape produced by Clockwork's existing SSH+MySQL puller
 * (App\Services\Wordfence\WordfenceBlocksPuller). Wordfence stores blocks
 * in `<prefix>wfBlocks7` with the IP packed as BINARY(16) — we use
 * INET6_NTOA() to convert. We deliberately skip country-block (`cbl`) and
 * pattern-block (`pat`) rows because their IP column doesn't hold a usable
 * IPv4/IPv6 value.
 *
 * If the wfBlocks7 table doesn't exist (Wordfence not installed, or older
 * than wfBlocks7), we return an empty `blocks` array — never a 500.
 */
class WordfenceBlocksRoute
{
    private const IP_TYPES = [
        'brute',
        'lockout',
        'lockout_logged_in',
        'throttle',
        'wfsn-temporary',
        'wfsn-permanent',
        'manual',
    ];

    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/wordfence-blocks', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $prefix = $wpdb->prefix;
        $table = $prefix . 'wfBlocks7';

        $existsSql = $wpdb->prepare('SHOW TABLES LIKE %s', $table);
        $existing = $wpdb->get_var($existsSql);
        if ($existing !== $table) {
            return new WP_REST_Response([
                'ok' => true,
                'blocks' => [],
            ]);
        }

        $typeList = "'" . implode("','", self::IP_TYPES) . "'";
        $sql = "SELECT INET6_NTOA(IP) AS ip, type, reason, expiration "
            . "FROM `{$table}` "
            . "WHERE type IN ({$typeList}) "
            . 'AND (expiration = 0 OR expiration > UNIX_TIMESTAMP())';

        $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $wpdb->suppress_errors(false);

        if (! is_array($rows)) {
            return new WP_REST_Response([
                'ok' => true,
                'blocks' => [],
            ]);
        }

        $out = [];
        foreach ($rows as $row) {
            $ip = trim((string) ($row['ip'] ?? ''));
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $expiration = (int) ($row['expiration'] ?? 0);
            $expiresAt = $expiration > 0 ? gmdate('c', $expiration) : null;

            $out[] = [
                'ip' => $ip,
                'expires_at' => $expiresAt,
                'source_table' => $table,
                'reason' => isset($row['reason']) ? (string) $row['reason'] : null,
                'type' => isset($row['type']) ? (string) $row['type'] : null,
            ];
        }

        return new WP_REST_Response([
            'ok' => true,
            'blocks' => $out,
        ]);
    }
}
