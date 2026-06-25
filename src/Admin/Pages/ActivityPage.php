<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\ActionLog\Repository;
use ClockworkCompanion\Admin\Layout;

/**
 * Tools → Clockwork → Activity admin page.
 *
 * Client-facing "what we did this month" view. Reads from the
 * wp_clockwork_action_log table that Clockwork pushes rows into via the
 * /action-log/append endpoint.
 *
 * Care-plan banner: soft green card ("You're on a care plan") or amber card
 * ("You're not on a care plan") with icon and warmer copy.
 * Summary card: hero count, care-plan note, two-column action tile grid
 * sorted by volume with friendly labels and icons.
 */
class ActivityPage
{
    /**
     * Activity is the default landing for the parent "Clockwork" menu, so its
     * effective slug is the parent's. The constant is retained for any callers
     * that want a stable reference; both resolve to the same admin page URL.
     */
    public const SLUG = 'clockwork';

    public static function render(): void
    {
        Layout::render('activity', [self::class, 'renderBody']);
    }

    /** Page size for the activity table — kept in sync with SecurityPage. */
    public const PER_PAGE = 25;

    public static function renderBody(): void
    {
        $month = isset($_GET['month']) ? (string) $_GET['month'] : '';
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = gmdate('Y-m');
        }

        $start = $month . '-01 00:00:00';
        $end = gmdate('Y-m-t 23:59:59', strtotime($month . '-01 00:00:00'));

        $earliest = Repository::earliestRanAt();
        $onCarePlan = Repository::latestCarePlanFlag();

        // Totals are unpaginated — the hero number should always reflect the
        // full month, not just the visible page.
        $monthTotal = Repository::countInWindow($start, $end);

        $currentPage = Layout::currentPage();
        $offset = ($currentPage - 1) * self::PER_PAGE;
        $rows = Repository::findInWindowPaged($start, $end, $offset, self::PER_PAGE);

        // Separate unpaginated read for the tile breakdown so it matches the
        // hero count even when the table is paged.
        $totalsRows = Repository::findInWindow($start, $end, 5000);

        Layout::pageHeader(
            'Activity',
            'A log of the maintenance work Clockwork Web Dev has performed on this site.'
        );

        self::renderCarePlanBanner($onCarePlan);
        self::renderMonthPicker($month, $earliest);
        self::renderTotals($totalsRows, $monthTotal, $month, $onCarePlan);
        self::renderTable($rows);

        Layout::renderPagination(
            $monthTotal,
            self::PER_PAGE,
            $currentPage,
            ['page' => self::SLUG, 'month' => $month],
        );

        self::renderInlineStyles();
    }

    private static function renderCarePlanBanner(bool $onCarePlan): void
    {
        if ($onCarePlan) {
            ?>
            <div class="cwk-plan-banner cwk-plan-banner--on">
                <div class="cwk-plan-banner__icon-wrap">
                    <?php echo self::confettiIcon(); ?>
                </div>
                <div class="cwk-plan-banner__text">
                    <strong class="cwk-plan-banner__title">You're on a care plan</strong>
                    <span class="cwk-plan-banner__sub">Sit back and relax — everything below is handled for you and fully covered by your plan.</span>
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="cwk-plan-banner cwk-plan-banner--off">
                <div class="cwk-plan-banner__icon-wrap">
                    <?php echo self::clockIcon(); ?>
                </div>
                <div class="cwk-plan-banner__text">
                    <strong class="cwk-plan-banner__title">You're not on a care plan</strong>
                    <span class="cwk-plan-banner__sub">Work shown here may be billed separately. A care plan covers updates, scans, and monitoring automatically — <button type="button" class="cwk-support-trigger cwk-link-btn">talk to Clockwork Web Dev</button> about adding one.</span>
                </div>
            </div>
            <?php
        }
    }

    private static function renderMonthPicker(string $current, ?string $earliest): void
    {
        $now = gmdate('Y-m');
        $months = [];

        // Show the last 6 months + current. Skip months earlier than our
        // earliest known row, so we don't show empty months prior to
        // Companion installation.
        for ($i = 0; $i <= 6; $i++) {
            $ts = strtotime("{$now}-01 -{$i} months");
            if (! $ts) {
                continue;
            }
            $value = gmdate('Y-m', $ts);
            if ($earliest !== null && $value < substr($earliest, 0, 7)) {
                break;
            }
            $months[] = $value;
        }
        $months = array_reverse($months);

        ?>
        <div class="cwk-month-picker">
            <span class="cwk-month-picker__label">Month</span>
            <?php foreach ($months as $m) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&month=' . $m)); ?>"
                   class="cwk-month-btn <?php echo $m === $current ? 'cwk-month-btn--active' : ''; ?>">
                    <?php echo esc_html(gmdate('M Y', strtotime($m . '-01'))); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  Unpaginated for accurate totals.
     */
    private static function renderTotals(array $rows, int $total, string $month, bool $onCarePlan): void
    {
        $byType = [];
        foreach ($rows as $r) {
            $t = (string) ($r['action_type'] ?? '');
            if ($t === '') {
                continue;
            }
            $byType[$t] = ($byType[$t] ?? 0) + 1;
        }
        arsort($byType);

        $config = self::typeConfig();
        $monthLabel = gmdate('M Y', strtotime($month . '-01'));
        ?>
        <div class="cwk-summary-card">
            <span class="cwk-summary-card__month"><?php echo esc_html($monthLabel); ?></span>

            <div class="cwk-summary-card__hero">
                <span class="cwk-summary-card__number"><?php echo (int) $total; ?></span>
                <span class="cwk-summary-card__label">actions this month</span>
            </div>

            <?php if ($onCarePlan) : ?>
                <div class="cwk-summary-card__note">
                    <span class="cwk-summary-card__sparkle">✦</span>
                    Run automatically — all part of your care plan
                </div>
            <?php else : ?>
                <div class="cwk-summary-card__note cwk-summary-card__note--off">
                    Some work shown here may be billed separately.
                </div>
            <?php endif; ?>

            <?php if (! empty($byType)) : ?>
                <div class="cwk-action-grid">
                    <?php foreach ($byType as $type => $count) : ?>
                        <?php
                        $cfg = $config[$type] ?? null;
                        $label = $cfg ? $cfg['label'] : ucwords(str_replace('_', ' ', $type));
                        $iconName = $cfg ? $cfg['icon'] : 'default';
                        ?>
                        <div class="cwk-action-tile">
                            <span class="cwk-action-tile__icon"><?php echo self::typeIcon($iconName); ?></span>
                            <span class="cwk-action-tile__label"><?php echo esc_html($label); ?></span>
                            <span class="cwk-action-tile__count"><?php echo (int) $count; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function renderTable(array $rows): void
    {
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
                <h3 style="margin-top: 0;">All activity</h3>
                <?php if (empty($rows)) : ?>
                    <p style="color: #6b7280;">No activity recorded for this month yet.</p>
                <?php else : ?>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 14ch;">When</th>
                                <th style="width: 14ch;">Action</th>
                                <th>Summary</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r) : ?>
                                <?php
                                $when = isset($r['ran_at']) ? mysql2date('M j, H:i', (string) $r['ran_at']) : '';
                                $type = (string) ($r['action_type'] ?? '');
                                $summary = (string) ($r['summary'] ?? '');
                                $ok = ! empty($r['ok']);
                                ?>
                                <tr>
                                    <td style="white-space: nowrap; color: #6b7280; font-size: 12px;">
                                        <?php echo esc_html($when); ?>
                                    </td>
                                    <td style="white-space: nowrap; font-size: 12px;">
                                        <?php echo esc_html(self::labelForType($type)); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($summary); ?>
                                        <?php if (! $ok) : ?>
                                            <span style="background: #fee2e2; color: #b91c1c; font-size: 10px; padding: 2px 6px; border-radius: 999px; margin-left: 6px;">failed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Tile config: plural labels + icon names for the summary grid.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    private static function typeConfig(): array
    {
        return [
            'security_scan'           => ['label' => 'Security scans',          'icon' => 'shield'],
            'performance_scan'        => ['label' => 'Performance scans',       'icon' => 'gauge'],
            'plugin_update'           => ['label' => 'Plugin updates',          'icon' => 'plugin'],
            'uptime_check'            => ['label' => 'Uptime checks',           'icon' => 'pulse'],
            'companion_install'       => ['label' => 'Companion installs',      'icon' => 'download'],
            'auto_updates_configured' => ['label' => 'Auto-updates configured', 'icon' => 'refresh'],
            'companion_update'        => ['label' => 'Companion updates',       'icon' => 'download'],
            'theme_update'            => ['label' => 'Theme updates',           'icon' => 'theme'],
            'core_update'             => ['label' => 'Core updates',            'icon' => 'wp'],
            'sso_login'               => ['label' => 'SSO logins',              'icon' => 'login'],
            'manual_ban'              => ['label' => 'Lockouts',                'icon' => 'ban'],
            'manual_unban'            => ['label' => 'Unlocks',                 'icon' => 'check'],
            'review_approve'          => ['label' => 'Reviews approved',        'icon' => 'check'],
            'review_dismiss'          => ['label' => 'Reviews dismissed',       'icon' => 'dismiss'],
            'care_plan_toggled'       => ['label' => 'Care plan changes',       'icon' => 'plan'],
            'backup'                  => ['label' => 'Backups',                 'icon' => 'backup'],
        ];
    }

    private static function labelForType(string $type): string
    {
        $labels = [
            'plugin_update'           => 'Plugin update',
            'sso_login'               => 'SSO login',
            'companion_install'       => 'Companion install',
            'companion_update'        => 'Companion update',
            'manual_ban'              => 'Lockout',
            'manual_unban'            => 'Unlock',
            'review_approve'          => 'Review approved',
            'review_dismiss'          => 'Review dismissed',
            'care_plan_toggled'       => 'Care plan change',
            'security_scan'           => 'Security scan',
            'performance_scan'        => 'Performance scan',
            'uptime_check'            => 'Uptime check',
            'auto_updates_configured' => 'Auto-updates configured',
            'theme_update'            => 'Theme update',
            'core_update'             => 'Core update',
            'backup'                  => 'Backup',
        ];
        return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    private static function typeIcon(string $icon): string
    {
        $svgs = [
            'shield'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
            'gauge'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/><line x1="12" y1="7" x2="12" y2="12"/></svg>',
            'plugin'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
            'pulse'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
            'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
            'refresh'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
            'theme'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
            'wp'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>',
            'login'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
            'ban'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
            'check'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            'dismiss'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
            'plan'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
            'backup'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
            'default'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>',
        ];

        return $svgs[$icon] ?? $svgs['default'];
    }

    private static function confettiIcon(): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M5.8 11.3 2 22l10.7-3.79"/>'
            . '<path d="m22 2-7 20-4-9-9-4 20-7"/>'
            . '<circle cx="4" cy="3" r="1.2" fill="currentColor" stroke="none"/>'
            . '<circle cx="15" cy="2" r="1.2" fill="currentColor" stroke="none"/>'
            . '<circle cx="22" cy="8" r="1.2" fill="currentColor" stroke="none"/>'
            . '<circle cx="22" cy="20" r="1.2" fill="currentColor" stroke="none"/>'
            . '</svg>';
    }

    private static function clockIcon(): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<circle cx="12" cy="12" r="10"/>'
            . '<polyline points="12 6 12 12 16 14"/>'
            . '</svg>';
    }

    private static function renderInlineStyles(): void
    {
        ?>
        <style>
        /* ── Month picker ────────────────────────────────────────── */
        .cwk-month-picker {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 16px;
            padding: 0 2px;
        }
        .cwk-month-picker__label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #6b7280;
            margin-right: 4px;
        }
        .cwk-month-btn {
            display: inline-flex;
            align-items: center;
            padding: 6px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            border: 1.5px solid #4f46e5;
            color: #4f46e5;
            background: #fff;
            line-height: 1;
            cursor: pointer;
        }
        .cwk-month-btn--active {
            background: #4f46e5;
            color: #fff;
        }
        .cwk-month-btn:hover {
            text-decoration: none;
        }
        .cwk-month-btn:hover:not(.cwk-month-btn--active) {
            background: #eef2ff;
        }

        /* ── Care plan banner ─────────────────────────────────────── */
        .cwk-plan-banner {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px 24px;
            border-radius: 12px;
            margin-bottom: 16px;
        }
        .cwk-plan-banner--on  { background: #f0fdf4; }
        .cwk-plan-banner--off { background: #fffbeb; }

        .cwk-plan-banner__icon-wrap {
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            background: #fff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cwk-plan-banner__icon-wrap svg { width: 24px; height: 24px; }
        .cwk-plan-banner--on  .cwk-plan-banner__icon-wrap { color: #16a34a; }
        .cwk-plan-banner--off .cwk-plan-banner__icon-wrap { color: #d97706; }

        .cwk-plan-banner__text { display: flex; flex-direction: column; gap: 4px; }
        .cwk-plan-banner__title { font-size: 16px; font-weight: 700; }
        .cwk-plan-banner--on  .cwk-plan-banner__title { color: #15803d; }
        .cwk-plan-banner--off .cwk-plan-banner__title { color: #92400e; }
        .cwk-plan-banner__sub { font-size: 14px; color: #4b5563; font-weight: 400; }

        /* ── Summary / hero card ──────────────────────────────────── */
        .cwk-summary-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 28px;
            position: relative;
            margin-bottom: 16px;
        }
        .cwk-summary-card__month {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 12px;
            color: #6b7280;
            background: #f3f4f6;
            padding: 4px 12px;
            border-radius: 999px;
        }
        .cwk-summary-card__hero {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin-bottom: 6px;
        }
        .cwk-summary-card__number {
            font-size: 56px;
            font-weight: 700;
            color: #111827;
            line-height: 1;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .cwk-summary-card__label {
            font-size: 18px;
            color: #6b7280;
        }
        .cwk-summary-card__note {
            font-size: 13px;
            color: #16a34a;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .cwk-summary-card__note--off { color: #92400e; }
        .cwk-summary-card__sparkle { font-size: 11px; }

        /* ── Action tiles grid ────────────────────────────────────── */
        .cwk-action-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            column-gap: 0;
            row-gap: 0;
        }
        .cwk-action-tile {
            display: flex;
            align-items: center;
            gap: 12px;
            background: transparent;
            border-radius: 0;
            padding: 14px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        /* Column gap via padding so border-bottom spans full card width */
        .cwk-action-tile:nth-child(odd)  { padding-right: 24px; }
        .cwk-action-tile:nth-child(even) { padding-left: 24px; }
        /* No border below the last row */
        .cwk-action-tile:last-child,
        .cwk-action-tile:nth-last-child(2):nth-child(odd) { border-bottom: none; }
        .cwk-action-tile__icon {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            background: #ede9fe;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4c1d95;
        }
        .cwk-action-tile__icon svg { width: 18px; height: 18px; }
        .cwk-action-tile__label {
            flex: 1;
            font-size: 13px;
            color: #1f2937;
            font-weight: 500;
        }
        .cwk-action-tile__count {
            flex-shrink: 0;
            background: #dcfce7;
            color: #15803d;
            font-size: 13px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        </style>
        <?php
    }
}
