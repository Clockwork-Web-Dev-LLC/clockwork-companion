<?php

namespace ClockworkCompanion\SecurityScans;

use ClockworkCompanion\ActionLog\Repository as ActionLogRepository;

/**
 * Companion-side counterpart to Clockwork's SucuriSiteCheckClient.
 *
 * Hits Sucuri's free public v3 API directly from the WP host, parses the
 * payload using the same decision tree Clockwork uses, and writes a
 * security_scan row into the local wp_clockwork_action_log table so the
 * Tools → Clockwork → Security page reflects the result immediately.
 *
 * Auth: none. Sucuri intentionally exposes the same scanner ManageWP and
 * dozens of other dashboards resell. Free tier is rate-limited around
 * 30 req/min, but a single button click is well within that.
 */
class SitecheckScanner
{
    private const ENDPOINT = 'https://sitecheck.sucuri.net/api/v3/';

    private const TIMEOUT = 30;

    /**
     * Run a Sucuri scan against the home_url() of this WP install and persist
     * the result. Returns a one-line summary suitable for showing in a flash
     * message.
     */
    public function runAndPersist(): string
    {
        $started = microtime(true);
        $url = (string) get_home_url();

        $response = wp_remote_get(self::ENDPOINT.'?scan='.urlencode($url), [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $elapsed = (int) ((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            ActionLogRepository::insert([
                'action_type' => 'security_scan',
                'target' => 'sitecheck',
                'summary' => 'Sucuri SiteCheck request failed.',
                'details' => ['status' => 'failed', 'error' => $err],
                'ok' => false,
                'error' => substr($err, 0, 480),
                'elapsed_ms' => $elapsed,
                'actor' => 'client',
                'care_plan_enabled' => ActionLogRepository::latestCarePlanFlag(),
            ]);

            return "Sucuri SiteCheck request failed: {$err}";
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            ActionLogRepository::insert([
                'action_type' => 'security_scan',
                'target' => 'sitecheck',
                'summary' => 'Sucuri SiteCheck returned HTTP '.$code.'.',
                'details' => ['status' => 'failed', 'http' => $code],
                'ok' => false,
                'error' => 'http_'.$code,
                'elapsed_ms' => $elapsed,
                'actor' => 'client',
                'care_plan_enabled' => ActionLogRepository::latestCarePlanFlag(),
            ]);

            return 'Sucuri SiteCheck returned HTTP '.$code;
        }

        $body = (string) wp_remote_retrieve_body($response);
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            ActionLogRepository::insert([
                'action_type' => 'security_scan',
                'target' => 'sitecheck',
                'summary' => 'Sucuri SiteCheck returned a non-JSON response.',
                'details' => ['status' => 'failed', 'body_excerpt' => substr($body, 0, 200)],
                'ok' => false,
                'error' => 'invalid_json',
                'elapsed_ms' => $elapsed,
                'actor' => 'client',
                'care_plan_enabled' => ActionLogRepository::latestCarePlanFlag(),
            ]);

            return 'Sucuri SiteCheck returned an unexpected response.';
        }

        $result = self::interpret($payload, $elapsed);

        ActionLogRepository::insert([
            'action_type' => 'security_scan',
            'target' => 'sitecheck',
            'summary' => $result['summary'],
            'details' => $result['details'],
            'ok' => $result['status'] !== 'failed',
            'error' => $result['error'],
            'elapsed_ms' => $elapsed,
            'actor' => 'client',
            'care_plan_enabled' => ActionLogRepository::latestCarePlanFlag(),
        ]);

        return $result['summary'];
    }

    /**
     * Parse a v3 payload into a security_scan row shape. Decision tree mirrors
     * Clockwork's SucuriSiteCheckClient::interpret() so a Companion-initiated
     * scan and a Clockwork-pushed scan look identical in the action_log.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, summary: string, details: array<string, mixed>, error: ?string}
     */
    private static function interpret(array $payload, int $elapsedMs): array
    {
        $scanFailures = self::dataGet($payload, 'warnings.scan_failed');
        if (is_array($scanFailures) && $scanFailures !== []) {
            $first = $scanFailures[0] ?? [];
            $msg = trim((string) (($first['msg'] ?? '').' '.($first['details'] ?? '')));

            return [
                'status' => 'failed',
                'summary' => 'Sucuri could not reach the site.',
                'details' => [
                    'status' => 'failed',
                    'scan_failed' => $scanFailures,
                ],
                'error' => $msg !== '' ? substr($msg, 0, 480) : 'scan_failed',
            ];
        }

        $blacklistHit = (bool) self::dataGet($payload, 'blacklist.flagged', false)
            || self::nonEmptyList(self::dataGet($payload, 'blacklist.warnings'))
            || self::nonEmptyList(self::dataGet($payload, 'BLACKLIST.WARN'));

        $malwareHit = (bool) self::dataGet($payload, 'malware.found', false)
            || self::nonEmptyList(self::dataGet($payload, 'malware.warnings'))
            || self::nonEmptyList(self::dataGet($payload, 'MALWARE.WARN'));

        if ($blacklistHit || $malwareHit) {
            $bits = [];
            if ($malwareHit) {
                $bits[] = 'malware detected';
            }
            if ($blacklistHit) {
                $bits[] = 'on a blacklist';
            }

            return [
                'status' => 'issues_found',
                'summary' => 'Sucuri SiteCheck — '.implode(', ', $bits).'.',
                'details' => [
                    'status' => 'issues_found',
                    'has_malware_hit' => $malwareHit,
                    'blacklist_hit' => $blacklistHit,
                    'scan' => $payload,
                ],
                'error' => null,
            ];
        }

        $rating = self::dataGet($payload, 'ratings.security.rating');
        $passed = self::dataGet($payload, 'ratings.security.passed');
        $bits = ['Sucuri SiteCheck — clean'];
        if (is_string($rating) && $rating !== '') {
            $bits[] = "security {$rating}";
        }
        if (is_string($passed) && $passed !== '') {
            $bits[] = "{$passed} checks";
        }

        return [
            'status' => 'clean',
            'summary' => implode(', ', $bits).'.',
            'details' => [
                'status' => 'clean',
                'has_malware_hit' => false,
                'blacklist_hit' => false,
                'rating' => $rating,
                'passed' => $passed,
            ],
            'error' => null,
        ];
    }

    /**
     * Lightweight data_get() — fetch a dotted path from a nested array.
     * @param  array<string, mixed>  $array
     */
    private static function dataGet(array $array, string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $current = $array;
        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private static function nonEmptyList(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}
