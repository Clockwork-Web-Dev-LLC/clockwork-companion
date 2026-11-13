<?php

namespace ClockworkCompanion\Rest;

/**
 * Sanity filter for block/lockout lists reported to Clockwork Control.
 *
 * Control turns these lists into IP bans across the fleet, but the data
 * comes from tables and options on the site that other plugins (or anyone
 * who can write to the database) control. Before it leaves the site:
 *   - addresses that can never be a legitimate remote attacker are dropped
 *     (private, loopback, link-local, reserved, carrier-grade NAT) — banning
 *     those can take down the site's own proxies, load balancer or health
 *     checks;
 *   - the list is capped at MAX_ROWS so a stuffed table cannot turn into an
 *     unbounded ban list.
 */
class ReportedBlocks
{
    public const MAX_ROWS = 500;

    public static function isBannable(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // 100.64.0.0/10 (RFC 6598 shared address space) is not covered by
        // the flags above on every PHP version.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            return ! ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000));
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  each with an 'ip' key
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
     */
    public static function filter(array $rows): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if (is_array($row) && self::isBannable((string) ($row['ip'] ?? ''))) {
                $kept[] = $row;
            }
        }

        return [
            'rows' => array_slice($kept, 0, self::MAX_ROWS),
            'truncated' => count($kept) > self::MAX_ROWS,
        ];
    }

    /**
     * Free text from another plugin's table, made safe to store and display
     * elsewhere: control characters removed, length bounded.
     */
    public static function cleanText(?string $text, int $max = 200): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
        if (function_exists('mb_substr')) {
            // mb_scrub() first so invalid UTF-8 cannot break the JSON response.
            return mb_substr(mb_scrub($text, 'UTF-8'), 0, $max, 'UTF-8');
        }

        return substr($text, 0, $max);
    }
}
