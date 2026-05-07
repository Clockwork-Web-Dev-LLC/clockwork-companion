<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\Layout;

/**
 * Traffic admin page (1.16.0+).
 *
 * Reads the cached traffic report from
 * wp_options['clockwork_companion_traffic_report'] (populated by Clockwork
 * posting to /wp-json/clockwork/v1/traffic-report) and renders a simplified
 * 30-day view: three hero stat boxes (today / 7-day / 30-day visits), a
 * stacked SVG bar chart by status code, and a "top paths · most recent day"
 * table.
 *
 * **Cadence honesty.** This page is explicitly NOT live. The agency's
 * monitoring app rolls up nginx access logs nightly and pushes a digest
 * to this endpoint once a day. The "Refreshed nightly" banner makes that
 * obvious so clients don't misread today's partial bar as a realtime
 * counter. Reloading the page mid-day will show the same numbers as the
 * last reload — no polling, no auto-refresh, no JS interval.
 */
class TrafficPage
{
    public const SLUG = 'clockwork-traffic';

    public const OPTION = 'clockwork_companion_traffic_report';

    public static function render(): void
    {
        Layout::render('traffic', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $report = get_option(self::OPTION, null);
        $report = is_array($report) ? $report : null;

        Layout::pageHeader(
            'Traffic',
            'A 30-day view of how much traffic your site is serving — sourced from your server\'s access logs and refreshed nightly.'
        );

        if ($report === null || empty($report['daily'])) {
            self::renderEmptyState();
            return;
        }

        self::renderCadenceBanner($report);
        self::renderHeroStats($report);
        self::renderChartCard($report);
        self::renderTopPathsCard($report);
        self::renderCaption($report);
    }

    private static function renderEmptyState(): void
    {
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
                <div class="clockwork-notice">
                    <strong>No traffic report yet.</strong>
                    Your hosting provider hasn't pushed a traffic report to this site yet.
                    Reports refresh nightly — please check back tomorrow, or contact your agency if this persists.
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderCadenceBanner(array $report): void
    {
        $fetchedAt = self::formatTimestamp($report['fetched_at'] ?? null);
        ?>
        <div class="clockwork-notice clockwork-notice--muted" style="margin-bottom: 16px;">
            <strong>Refreshed nightly.</strong>
            These are not live stats — your hosting provider's monitoring app rolls up your access logs once a day and pushes the result here.
            <?php if ($fetchedAt) : ?>
                Last refreshed <?php echo esc_html($fetchedAt); ?>.
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderHeroStats(array $report): void
    {
        $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
        $today = (int) ($totals['today'] ?? 0);
        $week = (int) ($totals['week_7d'] ?? 0);
        $month = (int) ($totals['month_30d'] ?? 0);
        $todayPartial = ! empty($report['today_partial']);
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-bottom: 6px;">Today</div>
                        <div style="font-size: 28px; font-weight: 600; color: #111;"><?php echo number_format($today); ?></div>
                        <?php if ($todayPartial) : ?>
                            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">partial — updates again overnight</div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-bottom: 6px;">Last 7 days</div>
                        <div style="font-size: 28px; font-weight: 600; color: #111;"><?php echo number_format($week); ?></div>
                        <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">visits</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-bottom: 6px;">Last 30 days</div>
                        <div style="font-size: 28px; font-weight: 600; color: #111;"><?php echo number_format($month); ?></div>
                        <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">visits</div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders 30 stacked SVG bars, one per day. Status classes are stacked
     * bottom-up: 2xx (green), 3xx (blue), 4xx (amber), 5xx (red). Native
     * <title> tooltip on hover — no JS dependency.
     *
     * @param  array<string, mixed>  $report
     */
    private static function renderChartCard(array $report): void
    {
        $daily = is_array($report['daily'] ?? null) ? $report['daily'] : [];
        // Only the last 30 days; older data ignored if present.
        $rows = array_slice($daily, -30);

        // Total height of each bar comes from total requests, NOT visits, so
        // the stacked status-class breakdown adds up correctly. y-axis label
        // = "requests/day" so this is honest about what's being rendered.
        $maxRequests = 0;
        foreach ($rows as $r) {
            if (is_array($r)) {
                $maxRequests = max($maxRequests, (int) ($r['requests'] ?? 0));
            }
        }
        if ($maxRequests <= 0) {
            $maxRequests = 1; // avoid div-by-zero on all-zero windows
        }

        $width = 720;
        $height = 200;
        $padX = 32;
        $padTop = 12;
        $padBottom = 24;
        $plotW = $width - ($padX * 2);
        $plotH = $height - $padTop - $padBottom;
        $colCount = max(count($rows), 1);
        $colW = $plotW / $colCount;
        $barW = max(2, $colW * 0.7);

        $colors = [
            '2xx' => '#16a34a',
            '3xx' => '#3b82f6',
            '4xx' => '#d97706',
            '5xx' => '#dc2626',
        ];
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__head">
                <h2>Last 30 days</h2>
                <span style="font-size: 12px; color: #6b7280;">requests/day, stacked by status</span>
            </div>
            <div class="clockwork-card__body">
                <div style="overflow-x: auto;">
                    <svg viewBox="0 0 <?php echo (int) $width; ?> <?php echo (int) $height; ?>"
                         preserveAspectRatio="xMidYMid meet"
                         style="width: 100%; height: auto; max-width: <?php echo (int) $width; ?>px; display: block;"
                         role="img" aria-label="30-day traffic chart">
                        <?php
                        // Y-axis baseline
                        $baselineY = $padTop + $plotH;
                        echo '<line x1="'.$padX.'" x2="'.($padX + $plotW).'" y1="'.$baselineY.'" y2="'.$baselineY.'" stroke="#d1d5db" stroke-width="1" />';

                        $i = 0;
                        foreach ($rows as $r) {
                            if (! is_array($r)) {
                                $i++;
                                continue;
                            }
                            $date = (string) ($r['date'] ?? '');
                            $s2 = max(0, (int) ($r['status_2xx'] ?? 0));
                            $s3 = max(0, (int) ($r['status_3xx'] ?? 0));
                            $s4 = max(0, (int) ($r['status_4xx'] ?? 0));
                            $s5 = max(0, (int) ($r['status_5xx'] ?? 0));
                            $totalReq = max(0, (int) ($r['requests'] ?? ($s2 + $s3 + $s4 + $s5)));

                            $colCenter = $padX + ($colW * ($i + 0.5));
                            $barX = $colCenter - ($barW / 2);

                            // Stacked heights, bottom-up: 2xx, 3xx, 4xx, 5xx
                            $cursorY = $baselineY;
                            $segments = [
                                ['key' => '2xx', 'count' => $s2],
                                ['key' => '3xx', 'count' => $s3],
                                ['key' => '4xx', 'count' => $s4],
                                ['key' => '5xx', 'count' => $s5],
                            ];

                            $tooltip = sprintf(
                                "%s — %s requests (2xx %s · 3xx %s · 4xx %s · 5xx %s)",
                                $date,
                                number_format($totalReq),
                                number_format($s2),
                                number_format($s3),
                                number_format($s4),
                                number_format($s5),
                            );

                            echo '<g><title>'.esc_html($tooltip).'</title>';
                            foreach ($segments as $seg) {
                                if ($seg['count'] <= 0) {
                                    continue;
                                }
                                $segH = ($seg['count'] / $maxRequests) * $plotH;
                                $cursorY -= $segH;
                                printf(
                                    '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="%s" />',
                                    $barX, $cursorY, $barW, $segH, $colors[$seg['key']]
                                );
                            }
                            // Date tick labels — every ~5 days to avoid crowding
                            if ($i % 5 === 0 || $i === $colCount - 1) {
                                $label = strlen($date) >= 10 ? substr($date, 5) : $date;
                                echo '<text x="'.number_format($colCenter, 2).'" y="'.($baselineY + 14).'" text-anchor="middle" font-size="10" fill="#6b7280">'.esc_html($label).'</text>';
                            }
                            echo '</g>';
                            $i++;
                        }

                        // Top of y-axis label
                        echo '<text x="'.($padX - 6).'" y="'.($padTop + 4).'" text-anchor="end" font-size="10" fill="#6b7280">'.esc_html(self::shortNumber($maxRequests)).'</text>';
                        echo '<text x="'.($padX - 6).'" y="'.($baselineY + 4).'" text-anchor="end" font-size="10" fill="#6b7280">0</text>';
                        ?>
                    </svg>
                </div>
                <div style="display: flex; gap: 18px; flex-wrap: wrap; padding: 8px 0 0; font-size: 12px; color: #4b5563;">
                    <?php foreach ($colors as $k => $color) : ?>
                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                            <span style="display: inline-block; width: 10px; height: 10px; background: <?php echo esc_attr($color); ?>; border-radius: 2px;"></span>
                            <?php echo esc_html($k); ?>
                        </span>
                    <?php endforeach; ?>
                    <span style="margin-left: auto; color: #6b7280;">Hover any bar for the per-status breakdown.</span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderTopPathsCard(array $report): void
    {
        $paths = is_array($report['top_paths'] ?? null) ? $report['top_paths'] : [];
        $date = (string) ($report['top_paths_date'] ?? '');

        if (empty($paths)) {
            return;
        }
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__head">
                <h2>Top paths
                    <?php if ($date !== '') : ?>
                        <span style="font-weight: 400; color: #6b7280; font-size: 12px;">· <?php echo esc_html($date); ?></span>
                    <?php endif; ?>
                </h2>
                <span style="font-size: 12px; color: #6b7280;">most recent day</span>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <table class="clockwork-table">
                    <thead>
                        <tr>
                            <th>Path</th>
                            <th style="text-align: right;">Hits</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paths as $row) : ?>
                            <?php if (! is_array($row)) continue; ?>
                            <tr>
                                <td class="mono"><?php echo esc_html((string) ($row['path'] ?? '—')); ?></td>
                                <td class="mono" style="text-align: right;"><?php echo number_format((int) ($row['hits'] ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderCaption(array $report): void
    {
        $fetched = self::formatTimestamp($report['fetched_at'] ?? null);
        ?>
        <p class="clockwork-meta-line" style="padding: 0 4px;">
            From your server's access logs &middot;
            Visits = unique IPs/day, excluding 403s and static assets &middot;
            Refreshed nightly<?php if ($fetched) : ?> &middot; Last refresh <?php echo esc_html($fetched); ?><?php endif; ?>
        </p>
        <?php
    }

    /**
     * Compact axis labels — 12,400 → 12.4k, 1,200,000 → 1.2M.
     */
    private static function shortNumber(int $n): string
    {
        if ($n >= 1_000_000) {
            return number_format($n / 1_000_000, 1).'M';
        }
        if ($n >= 1_000) {
            return number_format($n / 1_000, 1).'k';
        }
        return (string) $n;
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
        return wp_date('M j, Y g:i a', $ts);
    }
}
