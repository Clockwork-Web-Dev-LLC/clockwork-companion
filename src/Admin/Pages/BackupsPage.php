<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\WhiteLabel\WhiteLabel;

/**
 * Backups admin page.
 *
 * Reads the cached backups report from wp_options['clockwork_companion_backups_report']
 * (populated by Clockwork posting to /wp-json/clockwork/v1/backups-report) and
 * renders it in the Gravity-Forms-style chrome.
 *
 * Backup history is shown for EVERY site, plan or not — hosting tier keeps
 * 30 days, care plan extends to 90. The retention pill in the card header is
 * the canonical place that difference is communicated; the empty-state copy
 * stays plan-agnostic (it's about "no data yet," not "no feature for you").
 *
 * Empty state for a totally-missing report: explicit "agency hasn't pushed yet"
 * notice rather than a scary broken-feature message.
 */
class BackupsPage
{
    public const SLUG = 'clockwork-backups';

    public const OPTION = 'clockwork_companion_backups_report';

    /**
     * Page size for the history table when on a care plan. Off-plan sites get
     * 30 days of data total — fits on one page, so pagination is hidden there.
     */
    public const PAGE_SIZE = 30;

    public static function render(): void
    {
        Layout::render('backups', [self::class, 'renderBody']);
    }

    /**
     * Compact status for the wp-admin dashboard widget.
     *
     * @return array{active: bool, lastRunLabel: ?string, hasReport: bool}
     */
    public static function summary(): array
    {
        $report = get_option(self::OPTION, null);
        $report = is_array($report) ? $report : null;

        if ($report === null) {
            return ['active' => false, 'lastRunLabel' => null, 'hasReport' => false];
        }

        $config = $report['config'] ?? [];
        $active = (bool) ($config['files'] ?? false) || (bool) ($config['database'] ?? false);
        $history = is_array($report['history'] ?? null) ? $report['history'] : [];
        // Pressable reports carry an explicit last_backup_at instead of a
        // single merged history list (files/database run on independent
        // cadences, reported as two separate lists — see renderHistoryCard()).
        $lastRunAt = $report['last_backup_at'] ?? ($history[0]['date'] ?? null);
        $lastRunLabel = isset($lastRunAt) ? self::formatTimestamp($lastRunAt) : null;

        return ['active' => $active, 'lastRunLabel' => $lastRunLabel, 'hasReport' => true];
    }

    public static function renderBody(): void
    {
        $report = get_option(self::OPTION, null);
        $report = is_array($report) ? $report : null;

        $isAvailableScope = is_array($report) && ($report['history_scope'] ?? 'policy') === 'available';

        Layout::pageHeader(
            'Backups',
            $isAvailableScope
                ? 'Your host already keeps recent backups of your site — this page shows what\'s currently on record.'
                : 'Your site is backed up to off-site storage on a schedule. This page shows the current backup configuration.'
        );

        if ($report === null) {
            self::renderEmptyState();
            return;
        }

        self::renderConfigCard($report);
        self::renderOffsiteArchiveCard($report);
        self::renderHistoryCard($report);
    }

    /**
     * Pressable-only, care-plan-only: a second, independent 90-day copy of
     * this site's backups, archived off-host to S3 Glacier by a separate
     * standalone process (not this plugin, not the agency's monitoring app
     * directly — see that project's docs for why). Purely informational
     * plus, when a currently-valid link exists, a direct download of the
     * single most recent archived copy. Omitted entirely for sites not
     * enrolled — no false promise for a site that doesn't have this.
     *
     * @param  array<string, mixed>  $report
     */
    private static function renderOffsiteArchiveCard(array $report): void
    {
        $archive = $report['offsite_archive'] ?? null;
        if (! is_array($archive) || empty($archive['active'])) {
            return;
        }

        $lastArchivedAt = isset($archive['last_archived_at']) ? (string) $archive['last_archived_at'] : null;
        $fsUrl = isset($archive['fs_download_url']) ? (string) $archive['fs_download_url'] : null;
        $dbUrl = isset($archive['db_download_url']) ? (string) $archive['db_download_url'] : null;
        $expiresAt = isset($archive['download_expires_at']) ? strtotime((string) $archive['download_expires_at']) : false;
        // A stale/expired link would just fail with an S3 XML error page if
        // clicked — hide the buttons rather than let that happen. The
        // pushing job refreshes this at least daily, so this should be rare.
        $linksValid = $expiresAt !== false && $expiresAt > time();
        ?>
        <div class="clockwork-card" style="margin-bottom: 16px;">
            <div class="clockwork-card__body">
                <div class="clockwork-notice clockwork-notice--ok">
                    <strong>Also archived off-host for 90 days.</strong>
                    Independent of the backups above, your host's backups are additionally copied to secure, encrypted cold storage (Amazon S3 Glacier) twice a week, kept for 90 days — a second copy in case anything ever happened to your host account itself.
                    <?php if ($lastArchivedAt): ?>
                        <div style="margin-top: 6px; font-size: 12px; opacity: 0.8;">Last archived: <?php echo esc_html(self::formatTimestamp($lastArchivedAt)); ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($linksValid && ($fsUrl || $dbUrl)): ?>
                    <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <?php if ($fsUrl): ?>
                            <a href="<?php echo esc_url($fsUrl); ?>" class="button" download>Download latest filesystem backup</a>
                        <?php endif; ?>
                        <?php if ($dbUrl): ?>
                            <a href="<?php echo esc_url($dbUrl); ?>" class="button" download>Download latest database backup</a>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top: 6px; font-size: 11px; opacity: 0.65;">These download links are temporary and refresh automatically — if one doesn't work, check back after the next daily refresh.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderEmptyState(): void
    {
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
                <div class="clockwork-notice">
                    <strong>No backup report yet.</strong>
                    <?php echo esc_html(WhiteLabel::getAuthorName()); ?> hasn't pushed backup configuration to this site yet.
                    This usually means the next scheduled push hasn't run — please check back in a few hours,
                    or contact <?php echo esc_html(WhiteLabel::getAuthorName()); ?> if this persists.
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

        // Pressable has no schedule-config API to report a future run time
        // from — but we DO know the most recent backup (the newest history
        // row), which is a real, verifiable fact rather than a guess. Showing
        // "Not scheduled" here read as "nothing is happening," directly
        // contradicting a history table full of hourly backups right below it.
        $availableScope = ($report['history_scope'] ?? 'policy') === 'available';
        $lastBackupAt = $report['last_backup_at'] ?? null;

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

                    <?php if ($availableScope) : ?>
                        <dt>Last backup</dt>
                        <dd>
                            <?php if ($lastBackupAt) : ?>
                                <?php echo esc_html(self::formatTimestamp($lastBackupAt)); ?>
                            <?php else : ?>
                                <span class="clockwork-pill clockwork-pill--warn">None recorded yet</span>
                            <?php endif; ?>
                        </dd>
                    <?php else : ?>
                        <dt>Next scheduled run</dt>
                        <dd>
                            <?php if ($nextRun) : ?>
                                <?php echo esc_html(self::formatTimestamp($nextRun)); ?>
                            <?php else : ?>
                                <span class="clockwork-pill clockwork-pill--warn">Not scheduled</span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>

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
        $availableScope = ($report['history_scope'] ?? 'policy') === 'available';

        if ($availableScope) {
            // Filesystem and database backups run on independent cadences
            // (daily vs. hourly on Pressable) with their own real sizes —
            // shown as two separate tables rather than forced into paired
            // rows that would misrepresent which component ran when.
            $historyFiles = is_array($report['history_files'] ?? null) ? $report['history_files'] : [];
            $historyDatabase = is_array($report['history_database'] ?? null) ? $report['history_database'] : [];

            self::renderAvailableScopeHistoryTable('Filesystem backups', $historyFiles);
            self::renderAvailableScopeHistoryTable('Database backups', $historyDatabase);
            self::renderRefreshCaption($report);

            return;
        }

        $history = is_array($report['history'] ?? null) ? $report['history'] : [];
        $totalRuns = count($history);

        // Care plan determines the retention window the report was filtered to
        // before it was pushed (see Clockwork's PushCompanionBackupsReport).
        // Off-plan sites get 30 days, on-plan sites get 90 days. Default to
        // 30 if the field is missing, matching the off-plan baseline.
        $onCarePlan = ! empty($report['care_plan_enabled']);
        $retentionDays = (int) ($report['retention_days'] ?? 30);

        // Pagination: only relevant when there are more rows than fit on one
        // page. Off-plan sites are bounded at 30 days, so this only kicks in
        // for care-plan sites with >30 history entries.
        $page = isset($_GET['hp']) ? max(1, (int) $_GET['hp']) : 1;
        $totalPages = max(1, (int) ceil($totalRuns / self::PAGE_SIZE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $pageRows = array_slice($history, $offset, self::PAGE_SIZE);

        $retentionPillVariant = $onCarePlan ? 'ok' : 'warn';
        $retentionPillLabel = "Last {$retentionDays} days";
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__head">
                <h2>Backup History</h2>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <span class="clockwork-pill clockwork-pill--<?php echo esc_attr($retentionPillVariant); ?>">
                        <?php echo esc_html($retentionPillLabel); ?>
                    </span>
                    <?php if ($totalRuns > 0) : ?>
                        <span class="clockwork-pill clockwork-pill--info">
                            <?php echo (int) $totalRuns; ?> run<?php echo $totalRuns === 1 ? '' : 's'; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="clockwork-card__body" style="padding-bottom: 0;">
                <?php if ($onCarePlan) : ?>
                    <div class="clockwork-notice clockwork-notice--ok">
                        Your care plan includes 90 days of backup history.
                    </div>
                <?php else : ?>
                    <div class="clockwork-notice">
                        Your hosting plan includes 30 days of backup history.
                        <button type="button" class="cwk-support-trigger cwk-link-btn">Talk to <?php echo esc_html(WhiteLabel::getAuthorName()); ?></button> about a care plan to extend retention to 90 days.
                    </div>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <?php if ($totalRuns === 0) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            Backup history hasn't been indexed yet. Your backups are still running on <?php echo esc_html(WhiteLabel::getAuthorName()); ?>'s schedule —
                            this view will populate within 24 hours of <?php echo esc_html(WhiteLabel::getAuthorName()); ?>'s next sync. If you don't see runs after that, contact <?php echo esc_html(WhiteLabel::getAuthorName()); ?>.
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
                            <?php foreach ($pageRows as $row) : ?>
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
                    <?php self::renderPager($page, $totalPages, $totalRuns); ?>
                <?php endif; ?>
            </div>
        </div>

        <?php self::renderRefreshCaption($report); ?>
        <?php
    }

    /**
     * One of the two Pressable-scope history tables (Filesystem or
     * Database backups) — each entry has a real per-backup size (parsed by
     * Clockwork from Pressable's own title strings), shown newest-first,
     * capped at PAGE_SIZE with a plain "showing N of M" note rather than a
     * full pager (depth here varies a lot by how long the site's existed —
     * not worth building two independent paginators for yet).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function renderAvailableScopeHistoryTable(string $title, array $rows): void
    {
        $total = count($rows);
        $shown = array_slice($rows, 0, self::PAGE_SIZE);
        ?>
        <div class="clockwork-card" style="margin-bottom: 16px;">
            <div class="clockwork-card__head">
                <h2><?php echo esc_html($title); ?></h2>
                <?php if ($total > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--info">
                        <?php echo (int) $total; ?> backup<?php echo $total === 1 ? '' : 's'; ?> on record
                    </span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <?php if ($total === 0) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">No backups on record yet.</div>
                    </div>
                <?php else : ?>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shown as $row) : ?>
                                <?php if (! is_array($row)) continue; ?>
                                <tr>
                                    <td class="mono"><?php echo esc_html(self::formatTimestamp($row['date'] ?? null) ?: '—'); ?></td>
                                    <td>
                                        <span class="clockwork-pill clockwork-pill--info">
                                            <?php echo esc_html(ucfirst((string) ($row['type'] ?? 'automatic'))); ?>
                                        </span>
                                    </td>
                                    <td class="mono"><?php echo esc_html(self::formatBytes($row['bytes'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($total > self::PAGE_SIZE) : ?>
                        <div style="padding: 8px 20px 12px; font-size: 12px; color: #6b7280;">
                            Showing the most recent <?php echo self::PAGE_SIZE; ?> of <?php echo (int) $total; ?>.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderRefreshCaption(array $report): void
    {
        ?>
        <p class="clockwork-meta-line" style="padding: 0 4px;">
            Report last refreshed:
            <?php echo esc_html(self::formatTimestamp($report['fetched_at'] ?? null) ?: 'unknown'); ?>
            &middot; source: <?php echo esc_html((string) ($report['source'] ?? 'agency')); ?>
        </p>
        <?php
    }

    private static function renderPager(int $page, int $totalPages, int $totalRuns): void
    {
        if ($totalPages <= 1) {
            return;
        }
        $base = admin_url('admin.php?page='.self::SLUG);
        $first = max(1, ($page - 1) * self::PAGE_SIZE + 1);
        $last = min($totalRuns, $page * self::PAGE_SIZE);
        ?>
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; border-top: 1px solid var(--cwk-border, #e5e7eb); font-size: 12px; color: #6b7280;">
            <span>Showing <?php echo (int) $first; ?>–<?php echo (int) $last; ?> of <?php echo (int) $totalRuns; ?></span>
            <span style="display: flex; gap: 6px;">
                <?php if ($page > 1) : ?>
                    <a class="button button-small" href="<?php echo esc_url($base.'&hp='.($page - 1)); ?>">‹ Prev</a>
                <?php else : ?>
                    <span class="button button-small" style="opacity: 0.5; pointer-events: none;">‹ Prev</span>
                <?php endif; ?>
                <span style="padding: 4px 10px;">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <?php if ($page < $totalPages) : ?>
                    <a class="button button-small" href="<?php echo esc_url($base.'&hp='.($page + 1)); ?>">Next ›</a>
                <?php else : ?>
                    <span class="button button-small" style="opacity: 0.5; pointer-events: none;">Next ›</span>
                <?php endif; ?>
            </span>
        </div>
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
