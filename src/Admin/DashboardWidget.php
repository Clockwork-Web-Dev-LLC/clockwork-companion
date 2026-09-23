<?php

namespace ClockworkCompanion\Admin;

use ClockworkCompanion\Admin\Pages\ActivityPage;
use ClockworkCompanion\Admin\Pages\BackupsPage;
use ClockworkCompanion\Admin\Pages\PerformancePage;
use ClockworkCompanion\Admin\Pages\SecurityPage;
use ClockworkCompanion\Admin\Pages\TrafficPage;
use ClockworkCompanion\Admin\Pages\UptimePage;
use ClockworkCompanion\WhiteLabel\WhiteLabel;

/**
 * wp-admin Dashboard widget: a six-tile status grid (uptime, security,
 * backups, performance, traffic, tasks done) plus a support button that
 * links to WhiteLabel::getSupportUrl(). Administrators only.
 */
class DashboardWidget
{
    public function register(): void
    {
        add_action('wp_dashboard_setup', [self::class, 'registerWidget']);
    }

    public static function registerWidget(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'clockwork_support_widget',
            WhiteLabel::getAuthorName(),
            [self::class, 'renderWidget']
        );
    }

    public static function renderWidget(): void
    {
        self::renderStatsGrid();
        ?>
        $supportUrl = WhiteLabel::getSupportUrl();
        if ($supportUrl === '' || WhiteLabel::areHelpLinksHidden()) {
            return;
        }
        ?>
        <p style="margin: 0 0 12px; color: #374151; font-size: 13px; line-height: 1.5;">
            Need help with your website? Reach out and we'll get back to you shortly.
        </p>
        <a href="<?php echo esc_url($supportUrl); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
            <?php echo esc_html(WhiteLabel::getSupportButtonLabel()); ?>
        </a>
        <?php
    }

    /**
     * Six-tile "we've got you covered" status grid so a client glancing at
     * wp-admin sees their site is actively looked after, not just a support
     * button. Top row is health/status (uptime, security, backups) colored
     * by severity; bottom row is informational stats (performance, traffic,
     * tasks done) in a uniform brand-indigo style so the two rows read as
     * "is something wrong?" vs. "here's what we've been doing." Reuses each
     * page's summary() so the numbers here always agree with the full
     * Tools → Clockwork pages they link to.
     */
    private static function renderStatsGrid(): void
    {
        $uptime = UptimePage::summary();
        $security = SecurityPage::summary();
        $backups = BackupsPage::summary();
        $performance = PerformancePage::summary();
        $traffic = TrafficPage::summary();
        $activity = ActivityPage::summary();

        $uptimeVariant = match (true) {
            $uptime['state'] === 'down' => 'red',
            $uptime['pct30'] !== null && $uptime['pct30'] < 99.0 => 'warn',
            $uptime['hasHistory'] => 'green',
            default => 'muted',
        };
        $uptimeValue = $uptime['pct30'] !== null
            ? number_format($uptime['pct30'], 2).'%'
            : ($uptime['state'] === 'up' ? 'Up' : '—');
        $uptimeSub = $uptime['state'] === 'down' ? 'Site is down' : '30-day uptime';

        $backupsVariant = ! $backups['hasReport'] ? 'muted' : ($backups['active'] ? 'green' : 'warn');
        $backupsValue = ! $backups['hasReport'] ? 'Pending' : ($backups['active'] ? 'Backed up' : 'Disabled');
        $backupsSub = $backups['lastRunLabel'] !== null ? 'Last: '.$backups['lastRunLabel'] : 'No runs yet';

        $perfValue = $performance['hasScore'] ? $performance['score'].'/100' : 'Pending';
        $perfSub = $performance['hasScore'] ? 'Grade '.$performance['grade'].' · mobile' : 'No scan yet';

        $trafficValue = $traffic['visits30dLabel'] ?? 'Pending';
        $trafficSub = $traffic['hasReport'] ? 'Visits (30 days)' : 'No report yet';

        $tasksValue = (string) $activity['tasksThisMonth'];
        $tasksSub = 'Done this month';

        ?>
        <p class="cwk-dash-stats__intro">Here's what we're watching for you:</p>
        <div class="cwk-dash-stats">
            <?php
            self::renderStatTile('pulse', 'Uptime', $uptimeValue, $uptimeSub, $uptimeVariant, UptimePage::SLUG);
            self::renderStatTile('shield', 'Security', $security['label'], 'Last scans', $security['variant'], SecurityPage::SLUG);
            self::renderStatTile('cloud', 'Backups', $backupsValue, $backupsSub, $backupsVariant, BackupsPage::SLUG);
            self::renderStatTile('gauge', 'Performance', $perfValue, $perfSub, $performance['variant'], PerformancePage::SLUG);
            if (TrafficPage::isSupported()) {
                self::renderStatTile('bars', 'Traffic', $trafficValue, $trafficSub, $traffic['hasReport'] ? 'info' : 'muted', TrafficPage::SLUG);
            }
            self::renderStatTile('check', 'Tasks done', $tasksValue, $tasksSub, 'info', ActivityPage::SLUG);
            ?>
        </div>
        <style>
        .cwk-dash-stats__intro {
            margin: 0 0 8px;
            font-size: 12px;
            font-weight: 600;
            color: #4338ca;
        }
        .cwk-dash-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin: 0 0 16px;
        }
        .cwk-dash-stat {
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding: 12px 10px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            text-decoration: none;
            transition: border-color .15s, background .15s, transform .15s;
        }
        .cwk-dash-stat:hover {
            border-color: #c7d2fe;
            background: #fff;
            transform: translateY(-1px);
        }
        .cwk-dash-stat__head {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #6b7280;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .cwk-dash-stat__head svg { width: 13px; height: 13px; flex-shrink: 0; }
        .cwk-dash-stat__value {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.15;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .cwk-dash-stat__sub {
            font-size: 11px;
            color: #6b7280;
        }
        .cwk-dash-stat--green .cwk-dash-stat__value,
        .cwk-dash-stat--green .cwk-dash-stat__head { color: #15803d; }
        .cwk-dash-stat--green { border-left: 3px solid #16a34a; }
        .cwk-dash-stat--warn .cwk-dash-stat__value,
        .cwk-dash-stat--warn .cwk-dash-stat__head { color: #92400e; }
        .cwk-dash-stat--warn { border-left: 3px solid #f59e0b; }
        .cwk-dash-stat--red .cwk-dash-stat__value,
        .cwk-dash-stat--red .cwk-dash-stat__head { color: #b91c1c; }
        .cwk-dash-stat--red { border-left: 3px solid #dc2626; }
        .cwk-dash-stat--muted .cwk-dash-stat__value,
        .cwk-dash-stat--muted .cwk-dash-stat__head { color: #6b7280; }
        .cwk-dash-stat--muted { border-left: 3px solid #d1d5db; }
        .cwk-dash-stat--info .cwk-dash-stat__value,
        .cwk-dash-stat--info .cwk-dash-stat__head { color: #4338ca; }
        .cwk-dash-stat--info { border-left: 3px solid #6366f1; background: #f5f5ff; }
        .cwk-dash-stat--info:hover { background: #eef0ff; }
        @media (max-width: 380px) {
            .cwk-dash-stats { grid-template-columns: 1fr; }
        }
        </style>
        <?php
    }

    private static function renderStatTile(string $icon, string $label, string $value, string $sub, string $variant, string $pageSlug): void
    {
        $url = admin_url('admin.php?page='.$pageSlug);
        ?>
        <a href="<?php echo esc_url($url); ?>" class="cwk-dash-stat cwk-dash-stat--<?php echo esc_attr($variant); ?>">
            <span class="cwk-dash-stat__head"><?php echo self::dashIcon($icon); ?> <?php echo esc_html($label); ?></span>
            <span class="cwk-dash-stat__value"><?php echo esc_html($value); ?></span>
            <span class="cwk-dash-stat__sub"><?php echo esc_html($sub); ?></span>
        </a>
        <?php
    }

    /**
     * Minimal inline lucide-style icons so the widget doesn't pull in a
     * font-icon kit. Stroke-based, currentColor — picks up each tile's
     * status color.
     */
    private static function dashIcon(string $name): string
    {
        if ($name === 'pulse') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>';
        }
        if ($name === 'shield') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
        }
        if ($name === 'cloud') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19a4.5 4.5 0 0 0 0-9 6.5 6.5 0 0 0-12.6-2A5 5 0 0 0 6 19h11.5z"/></svg>';
        }
        if ($name === 'gauge') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg>';
        }
        if ($name === 'bars') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>';
        }
        if ($name === 'check') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
        }

        return '';
    }
}
