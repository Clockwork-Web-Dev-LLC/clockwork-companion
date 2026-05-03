<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/wp-cron
 *
 * Health snapshot of WP-Cron. WP-Cron drives plugin updates, scheduled
 * posts, transient cleanup, backup plugins, etc. — when it stops, those
 * silently rot. The most common breakage: someone sets DISABLE_WP_CRON in
 * wp-config.php intending to switch to a real system cron, then never sets
 * up the system cron.
 *
 * "Overdue" means a hook's scheduled timestamp is in the past. Some lag
 * is normal (WP-Cron only fires on page loads), but >5min on a busy site
 * or anything overdue at all on a dead site means cron isn't running.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "disabled_in_wp_config": false,
 *     "alternate_cron": false,
 *     "doing_cron_lock_age": null,
 *     "counts": {
 *       "total_events": 47,
 *       "overdue": 0,
 *       "due_within_5min": 2,
 *       "unique_hooks": 23
 *     },
 *     "oldest_overdue": {
 *       "hook": "wp_version_check",
 *       "scheduled_at": "2026-05-02T18:00:00+00:00",
 *       "overdue_seconds": 7200
 *     } | null
 *   }
 */
class CronRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/wp-cron', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $now = time();
        $cron = _get_cron_array();
        if (! is_array($cron)) {
            $cron = [];
        }

        $totalEvents = 0;
        $overdue = 0;
        $dueSoon = 0;
        $uniqueHooks = [];
        $oldestOverdueTs = null;
        $oldestOverdueHook = null;

        foreach ($cron as $timestamp => $hooks) {
            if (! is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hookName => $events) {
                if (! is_array($events)) {
                    continue;
                }
                $count = count($events);
                $totalEvents += $count;
                $uniqueHooks[$hookName] = true;

                if ($timestamp < $now) {
                    $overdue += $count;
                    if ($oldestOverdueTs === null || $timestamp < $oldestOverdueTs) {
                        $oldestOverdueTs = (int) $timestamp;
                        $oldestOverdueHook = (string) $hookName;
                    }
                } elseif ($timestamp <= $now + 300) {
                    $dueSoon += $count;
                }
            }
        }

        // doing_cron is a transient WP sets while a cron run is in flight.
        // A stuck value > a few minutes old means a cron run is wedged.
        $doingCron = get_transient('doing_cron');
        $doingCronLockAge = (is_numeric($doingCron) && (int) $doingCron > 0)
            ? max(0, $now - (int) $doingCron)
            : null;

        return [
            'ok' => true,
            'disabled_in_wp_config' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true,
            'alternate_cron' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON === true,
            'doing_cron_lock_age' => $doingCronLockAge,
            'counts' => [
                'total_events' => $totalEvents,
                'overdue' => $overdue,
                'due_within_5min' => $dueSoon,
                'unique_hooks' => count($uniqueHooks),
            ],
            'oldest_overdue' => $oldestOverdueTs !== null ? [
                'hook' => $oldestOverdueHook,
                'scheduled_at' => gmdate('c', $oldestOverdueTs),
                'overdue_seconds' => $now - $oldestOverdueTs,
            ] : null,
        ];
    }
}
