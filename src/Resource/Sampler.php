<?php

namespace ClockworkCompanion\Resource;

/**
 * Records per-request CPU + memory usage into the hourly bucket table.
 *
 * Hooks into `shutdown` (after WP has emitted the response, so the timing
 * impact on TTFB is zero). Captures the worker process's `getrusage()`
 * counters at boot time, computes the delta on shutdown, and writes one
 * UPSERT to wp_clockwork_resource_hourly.
 *
 * `getrusage()` reports user+system CPU microseconds consumed by the calling
 * process. PHP-FPM workers are reused across requests, so subtracting the
 * snapshot at request start from the value at request end gives the cost of
 * this request. `memory_get_peak_usage(true)` returns the peak resident
 * memory of this request specifically (peak resets per request inside FPM).
 *
 * Failure modes are swallowed: this code MUST NOT break a request. Any
 * exception or fatal in the sampler is caught and logged via error_log when
 * WP_DEBUG_LOG is on.
 */
class Sampler
{
    /**
     * Remote toggle flag — Clockwork POSTs to /resource-sampler-config to flip
     * this. Default true: 1.17.0 sites that upgrade in-place pick up sampling
     * automatically.
     */
    public const OPTION_ENABLED = 'clockwork_companion_resource_sampler_enabled';

    /** @var array{ru_utime_tv_sec: int, ru_utime_tv_usec: int, ru_stime_tv_sec: int, ru_stime_tv_usec: int}|null */
    private static ?array $startUsage = null;

    private static ?float $startWall = null;

    public static function isEnabled(): bool
    {
        // get_option default is true so a missing row counts as "on" — matches
        // the 1.17.0 install path where the option doesn't exist at all.
        $val = get_option(self::OPTION_ENABLED, true);

        return (bool) $val;
    }

    public static function setEnabled(bool $enabled): void
    {
        update_option(self::OPTION_ENABLED, $enabled, false);
    }

    public function register(): void
    {
        if (! function_exists('getrusage')) {
            // Some restricted environments (very rare on Linux WordPress
            // hosting) don't expose getrusage. Without it, we can't measure
            // CPU — quietly skip registration.
            return;
        }

        if (! self::isEnabled()) {
            // Operator paused sampling from Clockwork. Skip the shutdown hook
            // entirely so each request pays zero extra cost.
            return;
        }

        if ($this->isUntrackedCli()) {
            return;
        }

        // Snapshot start counters now. `init` priority 0 would be slightly
        // more accurate (less plugin-load overhead before our snapshot), but
        // we want the snapshot to include even plugin-load CPU so the rollup
        // reflects the full request cost. So snapshot at plugins_loaded
        // (which is when this class is constructed) by reading here at
        // register() time.
        self::$startUsage = self::readUsage();
        self::$startWall = microtime(true);

        add_action('shutdown', [$this, 'sample'], PHP_INT_MAX);
    }

    public function sample(): void
    {
        try {
            if (self::$startUsage === null || self::$startWall === null) {
                return;
            }

            $end = self::readUsage();
            if ($end === null) {
                return;
            }

            $cpuUs = self::cpuMicroseconds($end) - self::cpuMicroseconds(self::$startUsage);
            $wallUs = (int) round((microtime(true) - self::$startWall) * 1_000_000);
            $memPeak = (int) memory_get_peak_usage(true);

            Repository::record($cpuUs, $wallUs, $memPeak);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[clockwork-resource-sampler] '.$e->getMessage());
            }
        }
    }

    /**
     * CLI requests that aren't WP-Cron are typically one-off ops scripts
     * (wp-cli runs, deploy hooks). They distort the per-site rollup with
     * spiky one-time costs. Real per-request load comes from FPM workers.
     * We DO keep wp-cron CLI invocations — DISABLE_WP_CRON setups run cron
     * out of system cron, and that traffic IS real load.
     */
    private function isUntrackedCli(): bool
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
            return false;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return false;
        }

        return true;
    }

    /**
     * @return array{ru_utime_tv_sec: int, ru_utime_tv_usec: int, ru_stime_tv_sec: int, ru_stime_tv_usec: int}|null
     */
    private static function readUsage(): ?array
    {
        $r = @getrusage();
        if (! is_array($r)) {
            return null;
        }

        return [
            'ru_utime_tv_sec' => (int) ($r['ru_utime.tv_sec'] ?? 0),
            'ru_utime_tv_usec' => (int) ($r['ru_utime.tv_usec'] ?? 0),
            'ru_stime_tv_sec' => (int) ($r['ru_stime.tv_sec'] ?? 0),
            'ru_stime_tv_usec' => (int) ($r['ru_stime.tv_usec'] ?? 0),
        ];
    }

    /**
     * @param  array{ru_utime_tv_sec: int, ru_utime_tv_usec: int, ru_stime_tv_sec: int, ru_stime_tv_usec: int}  $r
     */
    private static function cpuMicroseconds(array $r): int
    {
        return $r['ru_utime_tv_sec'] * 1_000_000 + $r['ru_utime_tv_usec']
            + $r['ru_stime_tv_sec'] * 1_000_000 + $r['ru_stime_tv_usec'];
    }
}
