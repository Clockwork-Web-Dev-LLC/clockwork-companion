<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\ActionLog\Repository;
use ClockworkCompanion\Admin\Layout;

/**
 * Tools → Clockwork → Performance admin page.
 *
 * Shows the latest Lighthouse score, Core Web Vitals (LCP, CLS, TBT, FCP, SI),
 * and a daily history table — care-plan-only.
 *
 * Off-plan customers see a greyed page with an upsell explaining what the
 * care plan adds. We deliberately don't surface historical scan rows when the
 * flag is off, even if data exists from a previous care-plan period — keeps
 * the value-of-care-plan promise visually consistent.
 */
class PerformancePage
{
    public const SLUG = 'clockwork-performance';

    public static function render(): void
    {
        Layout::render('performance', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $onCarePlan = Repository::latestCarePlanFlag();
        $latestMobile = Repository::latestByActionTypeAndTarget('performance_scan', 'mobile');
        $latestDesktop = Repository::latestByActionTypeAndTarget('performance_scan', 'desktop');
        $history = $onCarePlan ? Repository::findByActionType('performance_scan', 100) : [];

        Layout::pageHeader(
            'Performance',
            'Daily Lighthouse scan from Google PageSpeed Insights — same engine Google uses to grade sites for SEO. Google prioritizes mobile performance for ranking, but desktop scores matter too — both are shown here.'
        );

        self::renderCarePlanBanner($onCarePlan);
        self::renderHeroPair($onCarePlan, $latestMobile, $latestDesktop);
        self::renderHistorySection($onCarePlan, $history);
        self::renderInlineStyles();
    }

    private static function renderCarePlanBanner(bool $onCarePlan): void
    {
        if ($onCarePlan) {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #65a30d;">
                <div class="clockwork-card__body">
                    <strong>Daily speed checks are part of your care plan.</strong>
                    Clockwork Web Dev runs a Lighthouse scan against your homepage every day at 03:30 UTC and
                    flags regressions in your performance score.
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #f59e0b;">
                <div class="clockwork-card__body">
                    <strong>Add a care plan to unlock daily speed checks.</strong>
                    With a care plan, Clockwork Web Dev runs a Lighthouse scan every day — the same engine
                    Google uses to evaluate site performance. You'd see your <strong>Performance score</strong>,
                    <strong>Largest Contentful Paint</strong>, <strong>Cumulative Layout Shift</strong>, and
                    <strong>page weight</strong> trended over time, so a slow regression doesn't sneak past you.
                    <button type="button" class="cwk-support-trigger cwk-link-btn">Talk to Clockwork Web Dev</button> about adding a care plan.
                </div>
            </div>
            <?php
        }
    }

    /**
     * Renders the mobile and desktop "latest scan" cards side by side. Off
     * care-plan? Render nothing — the upsell banner above carries the message
     * and the empty cards would just be visual clutter.
     *
     * @param  array<string, mixed>|null  $mobile
     * @param  array<string, mixed>|null  $desktop
     */
    private static function renderHeroPair(bool $onCarePlan, ?array $mobile, ?array $desktop): void
    {
        if (! $onCarePlan) {
            return;
        }

        if ($mobile === null && $desktop === null) {
            ?>
            <div class="clockwork-card" style="margin-top: 16px;">
                <div class="clockwork-card__body">
                    <strong>No scans recorded yet.</strong>
                    Mobile runs daily at <strong>03:30 UTC</strong>, desktop at <strong>04:30 UTC</strong>. Results
                    will populate here within 24 hours.
                </div>
            </div>
            <?php
            return;
        }

        ?>
        <div class="clockwork-perf-hero-grid" style="margin-top: 16px;">
            <?php
            self::renderHero('mobile', $mobile);
            self::renderHero('desktop', $desktop);
            ?>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>|null  $latest
     */
    private static function renderHero(string $strategy, ?array $latest): void
    {
        $label = ucfirst($strategy);

        if ($latest === null) {
            ?>
            <div class="clockwork-perf-hero">
                <div class="clockwork-perf-hero__head">
                    <div>
                        <h2 class="clockwork-perf-hero__title"><?php echo esc_html($label); ?></h2>
                        <p class="clockwork-perf-hero__sub">No <?php echo esc_html(strtolower($label)); ?> scan yet.</p>
                    </div>
                    <span class="clockwork-pill clockwork-pill--muted">Pending</span>
                </div>
                <p class="clockwork-perf-hero__pending">
                    The next <?php echo esc_html(strtolower($label)); ?> run is scheduled for
                    <strong><?php echo esc_html($strategy === 'mobile' ? '03:30 UTC' : '04:30 UTC'); ?></strong>.
                </p>
            </div>
            <?php
            return;
        }

        $details = self::decodeDetails($latest['details'] ?? null);
        $score = isset($details['performance_score']) ? (int) $details['performance_score'] : null;
        $grade = self::letterGradeFor($score);
        $scoreClass = self::scoreColorClass($score);
        $ranAt = (string) ($latest['ran_at'] ?? '');
        $ok = ! empty($latest['ok']);

        ?>
        <div class="clockwork-perf-hero">
            <div class="clockwork-perf-hero__head">
                <div>
                    <h2 class="clockwork-perf-hero__title"><?php echo esc_html($label); ?></h2>
                    <p class="clockwork-perf-hero__sub">
                        <?php echo esc_html(self::humanTimeAgo($ranAt)); ?>
                    </p>
                </div>
                <?php if ($ok && $score !== null) : ?>
                    <div class="clockwork-perf-score clockwork-perf-score--<?php echo esc_attr($scoreClass); ?>">
                        <span class="clockwork-perf-score__grade"><?php echo esc_html($grade); ?></span>
                        <span class="clockwork-perf-score__pct"><?php echo (int) $score; ?>/100</span>
                    </div>
                <?php else : ?>
                    <span class="clockwork-pill clockwork-pill--warn">Failed</span>
                <?php endif; ?>
            </div>

            <?php if ($ok) : ?>
                <dl class="clockwork-perf-vitals">
                    <?php
                    self::renderVital('LCP', self::formatMs($details['lcp_ms'] ?? null), 'Largest Contentful Paint');
                    self::renderVital('CLS', self::formatCls($details['cls_x1000'] ?? null), 'Cumulative Layout Shift');
                    self::renderVital('TBT', self::formatMs($details['tbt_ms'] ?? null), 'Total Blocking Time');
                    self::renderVital('FCP', self::formatMs($details['fcp_ms'] ?? null), 'First Contentful Paint');
                    self::renderVital('Speed Index', self::formatMs($details['si_ms'] ?? null), '');
                    self::renderVital('Page weight', self::formatBytes($details['page_weight_bytes'] ?? null), '');
                    ?>
                </dl>
                <?php if ($scoreClass === 'orange' || $scoreClass === 'red') : ?>
                    <p class="clockwork-perf-hero__improve-note">
                        There may be opportunities to improve this score.
                        <a href="https://clockworkwd.com/contact/" target="_blank" rel="noopener">Talk to Clockwork Web Dev</a>
                        about performance optimization for this site.
                    </p>
                <?php endif; ?>
            <?php elseif (! empty($latest['error'])) : ?>
                <p class="clockwork-perf-hero__error">
                    <strong>Scan failed:</strong> <?php echo esc_html((string) $latest['error']); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function renderHistorySection(bool $onCarePlan, array $rows): void
    {
        $total = count($rows);
        ?>
        <div class="clockwork-card" style="margin-top: 16px;">
            <div class="clockwork-card__head">
                <h2>Scan History</h2>
                <?php if ($total > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--info">
                        <?php echo (int) $total; ?> scan<?php echo $total === 1 ? '' : 's'; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <?php if (! $onCarePlan) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            Performance history is part of the care plan. <a href="https://clockworkwd.com/contact/" target="_blank" rel="noopener">Talk to Clockwork Web Dev</a> about adding one.
                        </div>
                    </div>
                <?php elseif ($total === 0) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            No scans recorded yet. Once the daily run completes, history will populate here.
                        </div>
                    </div>
                <?php else : ?>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Strategy</th>
                                <th>Score</th>
                                <th>LCP</th>
                                <th>CLS</th>
                                <th>Page weight</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <?php
                                if (! is_array($row)) {
                                    continue;
                                }
                                $details = self::decodeDetails($row['details'] ?? null);
                                $score = isset($details['performance_score']) ? (int) $details['performance_score'] : null;
                                $strategy = ucfirst((string) ($details['strategy'] ?? '—'));
                                $ok = ! empty($row['ok']);
                                ?>
                                <tr>
                                    <td class="mono">
                                        <?php echo esc_html(self::humanTimeAgo((string) ($row['ran_at'] ?? ''))); ?>
                                        <div style="font-size: 11px; color: #6b7280;">
                                            <?php echo esc_html(self::formatTimestampUtc((string) ($row['ran_at'] ?? '')) ?: ''); ?>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($strategy); ?></td>
                                    <td>
                                        <?php if ($ok && $score !== null) : ?>
                                            <span class="clockwork-perf-pill clockwork-perf-pill--<?php echo esc_attr(self::scoreColorClass($score)); ?>">
                                                <?php echo (int) $score; ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="clockwork-pill clockwork-pill--warn">Failed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="mono"><?php echo esc_html(self::formatMs($details['lcp_ms'] ?? null)); ?></td>
                                    <td class="mono"><?php echo esc_html(self::formatCls($details['cls_x1000'] ?? null)); ?></td>
                                    <td class="mono"><?php echo esc_html(self::formatBytes($details['page_weight_bytes'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderVital(string $label, string $value, string $hint): void
    {
        ?>
        <div class="clockwork-perf-vital">
            <dt><?php echo esc_html($label); ?></dt>
            <dd><?php echo esc_html($value); ?></dd>
            <?php if ($hint !== '') : ?>
                <dd class="clockwork-perf-vital__hint"><?php echo esc_html($hint); ?></dd>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function letterGradeFor(?int $score): string
    {
        if ($score === null) {
            return '—';
        }

        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 50 => 'C',
            $score >= 30 => 'D',
            default => 'E',
        };
    }

    /**
     * Color class for Lighthouse-style threshold:
     *   green ≥ 90, orange 50–89, red < 50.
     */
    private static function scoreColorClass(?int $score): string
    {
        if ($score === null) {
            return 'muted';
        }
        if ($score >= 90) {
            return 'green';
        }
        if ($score >= 50) {
            return 'orange';
        }

        return 'red';
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>
     */
    private static function decodeDetails($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }

    private static function humanTimeAgo(string $ranAtUtc): string
    {
        if ($ranAtUtc === '') {
            return '—';
        }
        $ts = strtotime($ranAtUtc.' UTC');
        if (! $ts) {
            return '—';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $mins = (int) round($diff / 60);

            return $mins.' minute'.($mins === 1 ? '' : 's').' ago';
        }
        if ($diff < 86400) {
            $hours = (int) round($diff / 3600);

            return $hours.' hour'.($hours === 1 ? '' : 's').' ago';
        }
        $days = (int) round($diff / 86400);

        return $days.' day'.($days === 1 ? '' : 's').' ago';
    }

    private static function formatTimestampUtc(string $ranAtUtc): string
    {
        if ($ranAtUtc === '') {
            return '';
        }
        $ts = strtotime($ranAtUtc.' UTC');
        if (! $ts) {
            return '';
        }

        return wp_date('M j, Y g:i a', $ts);
    }

    private static function formatMs(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }
        $ms = (int) $value;
        if ($ms >= 1000) {
            return number_format($ms / 1000, 2).' s';
        }

        return number_format($ms).' ms';
    }

    private static function formatCls(mixed $clsX1000): string
    {
        if (! is_numeric($clsX1000)) {
            return '—';
        }

        return number_format(((int) $clsX1000) / 1000, 3);
    }

    private static function formatBytes(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }
        $bytes = (int) $value;
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0).' KB';
        }

        return $bytes.' B';
    }

    private static function renderInlineStyles(): void
    {
        ?>
        <style>
        .clockwork-perf-hero-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 16px;
        }
        .clockwork-perf-hero {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
        }
        .clockwork-perf-hero__pending {
            margin: 0;
            padding: 10px 12px;
            background: #f3f4f6;
            border-radius: 6px;
            color: #4b5563;
            font-size: 13px;
        }
        .clockwork-perf-hero__head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        .clockwork-perf-hero__title {
            margin: 0 0 2px;
            font-size: 16px;
            font-weight: 600;
            color: #111827;
        }
        .clockwork-perf-hero__sub {
            margin: 0;
            font-size: 13px;
            color: #6b7280;
        }
        .clockwork-perf-hero__error {
            margin: 0;
            padding: 10px 12px;
            background: #fef3c7;
            border-radius: 6px;
            color: #92400e;
            font-size: 13px;
        }
        .clockwork-perf-hero__improve-note {
            margin: 0;
            padding: 10px 12px;
            background: #f3f4f6;
            border-radius: 6px;
            color: #4b5563;
            font-size: 13px;
        }
        .clockwork-perf-score {
            display: flex;
            align-items: baseline;
            gap: 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .clockwork-perf-score__grade {
            font-size: 36px;
            font-weight: 700;
            line-height: 1;
        }
        .clockwork-perf-score__pct {
            font-size: 13px;
            color: #6b7280;
        }
        .clockwork-perf-score--green .clockwork-perf-score__grade { color: #15803d; }
        .clockwork-perf-score--orange .clockwork-perf-score__grade { color: #c2410c; }
        .clockwork-perf-score--red .clockwork-perf-score__grade { color: #b91c1c; }
        .clockwork-perf-score--muted .clockwork-perf-score__grade { color: #6b7280; }

        .clockwork-perf-vitals {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px 18px;
            margin: 0;
        }
        .clockwork-perf-vital dt {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .clockwork-perf-vital dd {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            color: #111827;
        }
        .clockwork-perf-vital__hint {
            font-size: 11px !important;
            font-weight: 400 !important;
            color: #9ca3af !important;
            margin-top: 2px !important;
            font-family: -apple-system, sans-serif !important;
        }

        .clockwork-perf-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px;
        }
        .clockwork-perf-pill--green { background: #dcfce7; color: #15803d; }
        .clockwork-perf-pill--orange { background: #ffedd5; color: #c2410c; }
        .clockwork-perf-pill--red { background: #fee2e2; color: #b91c1c; }
        .clockwork-perf-pill--muted { background: #f3f4f6; color: #6b7280; }
        </style>
        <?php
    }
}
