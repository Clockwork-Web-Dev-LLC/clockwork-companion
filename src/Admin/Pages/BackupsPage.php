<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\Layout;

/**
 * Backups admin page.
 *
 * Reads the cached backups report from wp_options['clockwork_companion_backups_report']
 * (populated by Clockwork posting to /wp-json/clockwork/v1/backups-report) and
 * renders it in the Gravity-Forms-style chrome.
 *
 * Empty state: explicit notice that the agency hasn't pushed a report yet —
 * not a scary "broken" message. SpinupWP's API limitation re. backup history
 * is also surfaced explicitly so clients understand why we're showing config
 * + next run rather than a list of past backup files.
 */
class BackupsPage
{
    public const SLUG = 'clockwork-backups';

    public const OPTION = 'clockwork_companion_backups_report';

    public static function render(): void
    {
        Layout::render('backups', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $report = get_option(self::OPTION, null);
        $report = is_array($report) ? $report : null;

        Layout::pageHeader(
            'Backups',
            'Your site is backed up to off-site storage on a schedule. This page shows the current backup configuration.'
        );

        if ($report === null) {
            self::renderEmptyState();
            return;
        }

        self::renderConfigCard($report);
        self::renderHistoryCard($report);
    }

    private static function renderEmptyState(): void
    {
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
                <div class="clockwork-notice">
                    <strong>No backup report yet.</strong>
                    Your hosting provider hasn't pushed backup configuration to this site yet.
                    This usually means the next scheduled push hasn't run — please check back in a few hours,
                    or contact your agency if this persists.
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderConfigCard(array $report): void
    {
        $config = $report['config'] ?? [];
        $files = (bool) ($config['files'] ?? false);
        $database = (bool) ($config['database'] ?? false);
        $nextRun = $config['next_run_time'] ?? null;
        $storage = $config['storage_provider'] ?? null;
        $excludePaths = $config['paths_to_exclude'] ?? null;
        $schedules = is_array($report['schedules'] ?? null) ? $report['schedules'] : [];

        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__head">
                <h2>Backup Configuration</h2>
                <?php if ($files || $database) : ?>
                    <span class="clockwork-pill clockwork-pill--ok"><span class="clockwork-pill__dot"></span> Active</span>
                <?php else : ?>
                    <span class="clockwork-pill clockwork-pill--off"><span class="clockwork-pill__dot"></span> Disabled</span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body">
                <dl class="clockwork-defs">
                    <dt>Files backup</dt>
                    <dd><?php echo $files ? '<span class="clockwork-pill clockwork-pill--ok">Enabled</span>' : '<span class="clockwork-pill clockwork-pill--off">Disabled</span>'; ?></dd>

                    <dt>Database backup</dt>
                    <dd><?php echo $database ? '<span class="clockwork-pill clockwork-pill--ok">Enabled</span>' : '<span class="clockwork-pill clockwork-pill--off">Disabled</span>'; ?></dd>

                    <dt>Schedules</dt>
                    <dd>
                        <?php if ($schedules === []) : ?>
                            <span class="clockwork-pill clockwork-pill--warn">None observed yet</span>
                        <?php else : ?>
                            <?php foreach ($schedules as $s) : ?>
                                <?php if (! is_array($s)) continue; ?>
                                <div style="margin-bottom: 6px;">
                                    <strong style="color: var(--cwk-text); font-family: -apple-system, sans-serif;">
                                        <?php echo esc_html(self::formatScheduleLabel($s)); ?>
                                    </strong>
                                    <?php if (! empty($s['observed_retention_days']) && (int) $s['observed_retention_days'] > 0) : ?>
                                        <span class="clockwork-pill clockwork-pill--info" style="margin-left: 6px;">
                                            ~<?php echo (int) $s['observed_retention_days']; ?>d retention observed
                                        </span>
                                    <?php endif; ?>
                                    <?php if (empty($s['confirmed'])) : ?>
                                        <span class="clockwork-pill clockwork-pill--warn" style="margin-left: 6px;">Provisional</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </dd>

                    <dt>Next scheduled run</dt>
                    <dd>
                        <?php if ($nextRun) : ?>
                            <?php echo esc_html(self::formatTimestamp($nextRun)); ?>
                        <?php else : ?>
                            <span class="clockwork-pill clockwork-pill--warn">Not scheduled</span>
                        <?php endif; ?>
                    </dd>

                    <?php if (is_array($storage) && (! empty($storage['region']) || ! empty($storage['bucket']))) : ?>
                        <dt>Storage destination</dt>
                        <dd>
                            <?php
                            $bits = [];
                            if (! empty($storage['bucket'])) {
                                $bits[] = esc_html((string) $storage['bucket']);
                            }
                            if (! empty($storage['region'])) {
                                $bits[] = esc_html((string) $storage['region']);
                            }
                            echo implode(' &middot; ', $bits);
                            ?>
                        </dd>
                    <?php endif; ?>

                    <?php if (! empty($excludePaths)) : ?>
                        <dt>Excluded paths</dt>
                        <dd><?php echo esc_html(is_array($excludePaths) ? implode(', ', $excludePaths) : (string) $excludePaths); ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderHistoryCard(array $report): void
    {
        $history = is_array($report['history'] ?? null) ? $report['history'] : [];
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__head">
                <h2>Backup History</h2>
                <?php if ($history !== []) : ?>
                    <span class="clockwork-pill clockwork-pill--info"><?php echo (int) count($history); ?> runs</span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <?php if ($history === []) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            No backup runs reported yet. If you've just enabled backups, the first run will appear here once it completes.
                        </div>
                    </div>
                <?php else : ?>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Database</th>
                                <th>Files</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row) : ?>
                                <?php if (! is_array($row)) continue; ?>
                                <tr>
                                    <td class="mono"><?php echo esc_html(self::formatTimestamp($row['date'] ?? null) ?: '—'); ?></td>
                                    <td>
                                        <span class="clockwork-pill clockwork-pill--info">
                                            <?php echo esc_html(ucfirst((string) ($row['type'] ?? 'daily'))); ?>
                                        </span>
                                    </td>
                                    <td class="mono"><?php echo esc_html(self::formatBytes($row['database_bytes'] ?? null)); ?></td>
                                    <td class="mono"><?php echo esc_html(self::formatBytes($row['files_bytes'] ?? null)); ?></td>
                                    <td><?php echo esc_html((string) ($row['notes'] ?? '—')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <p class="clockwork-meta-line" style="padding: 0 4px;">
            Report last refreshed:
            <?php echo esc_html(self::formatTimestamp($report['fetched_at'] ?? null) ?: 'unknown'); ?>
            &middot; source: <?php echo esc_html((string) ($report['source'] ?? 'agency')); ?>
        </p>
        <?php
    }

    /**
     * Human-friendly byte formatter — KB/MB/GB at 1024-based boundaries
     * with one decimal place. Returns "—" for null/zero.
     */
    private static function formatBytes(mixed $bytes): string
    {
        if ($bytes === null || $bytes === '' || ! is_numeric($bytes) || (int) $bytes <= 0) {
            return '—';
        }
        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, $bytes >= 100 || $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    /**
     * Format an inferred schedule entry as a human label using the WP site's
     * configured timezone. Examples:
     *   "Daily — 03:00 AM"
     *   "Weekly — Sundays 12:00 AM"
     *   "Monthly — 1st of the month at 12:00 AM"
     *
     * @param  array<string, mixed>  $s
     */
    private static function formatScheduleLabel(array $s): string
    {
        $cadence = (string) ($s['cadence'] ?? 'daily');
        $sampleAt = $s['sample_at'] ?? null;
        $ts = is_string($sampleAt) ? strtotime($sampleAt) : false;
        $time = $ts !== false ? wp_date('g:i A', $ts) : '—';

        if ($cadence === 'weekly') {
            $dow = (string) ($s['day_of_week'] ?? '');
            return "Weekly — {$dow}s {$time}";
        }
        if ($cadence === 'monthly') {
            $dom = (int) ($s['day_of_month'] ?? 1);
            $suffix = self::ordinalSuffix($dom);
            return "Monthly — {$dom}{$suffix} of the month at {$time}";
        }
        return "Daily — {$time}";
    }

    private static function ordinalSuffix(int $n): string
    {
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return 'th';
        }
        return ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$n % 10];
    }

    private static function formatTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        // wp_date uses the site's configured timezone — what the client expects.
        return wp_date('M j, Y g:i a', $ts);
    }
}
