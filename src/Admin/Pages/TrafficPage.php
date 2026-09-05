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
        if (! self::isSupported()) {
            if (function_exists('wp_safe_redirect') && function_exists('admin_url')) {
                wp_safe_redirect(admin_url('admin.php?page=' . \ClockworkCompanion\Admin\Menu::SLUG));
                exit;
            }
            return;
        }

        Layout::render('traffic', [self::class, 'renderBody']);
    }

    /**
     * Whether traffic monitoring is supported and has data for this site.
     *
     * When traffic stats cannot be gathered (e.g. SpinupWP server lacks SSH,
     * or provider doesn't support traffic logging, or explicitly disabled),
     * this returns false so the Traffic tab, menu entry, and dashboard tiles
     * are cleanly hidden from wp-admin.
     */
    public static function isSupported(): bool
    {
        $report = get_option(self::OPTION, null);
        if (! is_array($report)) {
            return false;
        }

        if (isset($report['supported']) && ! $report['supported']) {
            return false;
        }

        if (isset($report['enabled']) && ! $report['enabled']) {
            return false;
        }

        $hasDaily = ! empty($report['daily']) && is_array($report['daily']);
        $hasPeriodSummary = ! empty($report['period_summary']) && is_array($report['period_summary']);
        $hasData = ! isset($report['has_data']) || ! empty($report['has_data']);

        return ($hasDaily || $hasPeriodSummary) && $hasData;
    }

    /**
     * Compact status for the wp-admin dashboard widget — 30-day visit total.
     *
     * @return array{hasReport: bool, visits30dLabel: ?string}
     */
    public static function summary(): array
    {
        $report = get_option(self::OPTION, null);
        $report = is_array($report) ? $report : null;
        $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
        $visits = isset($totals['month_30d']) ? (int) $totals['month_30d'] : null;

        return [
            'hasReport' => $report !== null && $totals !== [],
            'visits30dLabel' => $visits !== null ? self::shortNumber($visits) : null,
        ];
    }

    public static function renderBody(): void
    {
        $report = get_option(self::OPTION, null);
        $report = is_array($report) ? $report : null;

        $periodSummary = is_array($report['period_summary'] ?? null) ? $report['period_summary'] : null;
        $isPeriodSummary = empty($report['daily']) && $periodSummary !== null;

        Layout::pageHeader(
            'Traffic',
            $isPeriodSummary
                ? 'How much traffic your site is serving — sourced from your host\'s own traffic stats and refreshed nightly.'
                : 'A 30-day view of how much traffic your site is serving — sourced from your server\'s access logs and refreshed nightly.'
        );

        if ($report === null || (empty($report['daily']) && $periodSummary === null)) {
            self::renderEmptyState();
            return;
        }

        // Pressable sites have no access-log rollup to source a daily chart
        // from — Pressable's own stats API only exposes period totals
        // (today/month/year), not a per-day breakdown. Render what's honest
        // instead of stretching that data into a chart it can't support.
        if ($isPeriodSummary) {
            self::renderPeriodSummaryCadenceBanner($report);
            self::renderPeriodSummary($periodSummary);
            self::renderCaption($report, periodSummary: true);
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
                    Clockwork Web Dev hasn't pushed a traffic report to this site yet.
                    Reports refresh nightly — please check back tomorrow, or contact Clockwork Web Dev if this persists.
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
            These are not live stats — Clockwork Web Dev's monitoring app rolls up your access logs once a day and pushes the result here.
            <?php if ($fetchedAt) : ?>
                Last refreshed <?php echo esc_html($fetchedAt); ?>.
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderPeriodSummaryCadenceBanner(array $report): void
    {
        $fetchedAt = self::formatTimestamp($report['fetched_at'] ?? null);
        ?>
        <div class="clockwork-notice clockwork-notice--muted" style="margin-bottom: 16px;">
            <strong>Refreshed nightly.</strong>
            Your hosting plan provides traffic in monthly-level totals rather than a daily breakdown, so there's no
            day-by-day chart here — just the running counts your host tracks.
            <?php if ($fetchedAt) : ?>
                Last refreshed <?php echo esc_html($fetchedAt); ?>.
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Simplified stat-grid view for hosts (Pressable) that only expose
     * period totals, not a daily rollup — see renderChartCard()'s docblock
     * for why the normal chart path can't be reused here.
     *
     * @param  array<string, mixed>  $periodSummary
     */
    private static function renderPeriodSummary(array $periodSummary): void
    {
        $boxes = [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'current_month' => 'This month',
            'last_month' => 'Last month',
            'last_12_months' => 'Last 12 months',
        ];
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
                <div style="font-size: 13px; font-weight: 600; color: #111; margin-bottom: 2px;">Page Views</div>
                <div style="font-size: 12px; color: #6b7280; margin-bottom: 16px;">
                    Counted by your host — views and unique visitors, by period.
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 24px;">
                    <?php foreach ($boxes as $key => $label) :
                        $bucket = is_array($periodSummary[$key] ?? null) ? $periodSummary[$key] : [];
                        $views = (int) ($bucket['views'] ?? 0);
                        $visitors = (int) ($bucket['visitors'] ?? 0);
                    ?>
                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-bottom: 6px;"><?php echo esc_html($label); ?></div>
                            <div style="font-size: 28px; font-weight: 600; color: #111;"><?php echo number_format($views); ?></div>
                            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;"><?php echo number_format($visitors); ?> visitor<?php echo $visitors === 1 ? '' : 's'; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
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
                <div style="font-size: 13px; font-weight: 600; color: #111; margin-bottom: 2px;">Visitors</div>
                <div style="font-size: 12px; color: #6b7280; margin-bottom: 16px;">
                    Estimated unique visitors — de-duplicated by IP, with bots and static files (images, CSS, JS) excluded.
                    This is a smaller, more human number than the request counts in the chart below.
                </div>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-bottom: 6px;">Today</div>
                        <div style="font-size: 28px; font-weight: 600; color: #111;"><?php echo number_format($today); ?></div>
                        <div style="font-size: 11px; color: #6b7280; margin-top: 4px;"><?php echo $todayPartial ? 'visits so far — updates again overnight' : 'visits'; ?></div>
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

        // Pad to a full 30-day window — today minus 29 → today inclusive —
        // so every day has its own column whether or not we have a rollup
        // row for it. Newly-onboarded sites get empty leading columns; this
        // makes "you've been tracked for 6 days, 24 more days of history
        // coming" visible at a glance, which the previous auto-crop hid by
        // making each bar absurdly wide.
        $byDate = [];
        foreach ($daily as $r) {
            if (is_array($r) && isset($r['date'])) {
                $byDate[(string) $r['date']] = $r;
            }
        }
        $rows = [];
        // Plugin runs in WP's configured timezone; the rollup keys use the
        // same calendar day so DateTime() defaults are fine here.
        $cursor = new \DateTime('today');
        $cursor->modify('-29 days');
        for ($i = 0; $i < 30; $i++) {
            $date = $cursor->format('Y-m-d');
            $rows[] = $byDate[$date] ?? [
                'date' => $date,
                'requests' => 0,
                'status_2xx' => 0,
                'status_3xx' => 0,
                'status_4xx' => 0,
                'status_5xx' => 0,
            ];
            $cursor->modify('+1 day');
        }

        // "Tracking started" annotation stays for newly-onboarded sites:
        // first day with non-zero requests in the visible window.
        $firstDataDate = null;
        foreach ($rows as $r) {
            if ((int) ($r['requests'] ?? 0) > 0) {
                $firstDataDate = (string) $r['date'];
                break;
            }
        }
        // Only annotate if tracking starts AFTER the leftmost slot — otherwise
        // the full 30 days are populated and there's no "starting" event to
        // surface.
        $cropped = $firstDataDate !== null && $firstDataDate !== ($rows[0]['date'] ?? '');
        $firstDate = $firstDataDate ?? '';

        // Total height of each bar comes from total requests, NOT visits, so
        // the stacked status-class breakdown adds up correctly. y-axis label
        // = "requests/day" so this is honest about what's being rendered.
        $observedMax = 0;
        foreach ($rows as $r) {
            if (is_array($r)) {
                $observedMax = max($observedMax, (int) ($r['requests'] ?? 0));
            }
        }
        // Round the chart top up to a "nice" tick boundary so the y-axis
        // reads as 5k / 10k / 15k rather than the raw observed max
        // (e.g. 9.2k). Bars scale against the rounded top, leaving a small
        // sliver of headroom above the tallest bar.
        $tickInfo = self::niceTicks(max(1, $observedMax));
        $maxRequests = $tickInfo['top'];

        // Intrinsic viewBox width — the SVG `width: 100%` styling stretches
        // this to the container, so bumping it gives finer detail at larger
        // sizes without distorting aspect. 1200 paired with height=200 is a
        // ~6:1 aspect that looks right at typical wp-admin column widths
        // without becoming absurdly tall on ultra-wide screens.
        $width = 1200;
        $height = 200;
        // Left gutter holds the y-axis labels. High-traffic sites tick into
        // the hundreds of thousands ("300k") or millions ("1.5M"); at the old
        // 32px the labels drew off the left edge of the viewBox and rendered
        // as clipped "00.0k". 56px fits the widest short-number label with
        // margin. Right gutter only needs enough to keep the last bar off the
        // edge, so it stays narrow rather than mirroring the left.
        $padLeft = 56;
        $padRight = 16;
        $padTop = 12;
        $padBottom = 24;
        $plotW = $width - $padLeft - $padRight;
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
                <h2>Last 30 days
                    <?php if ($cropped) : ?>
                        <span style="font-weight: 400; color: #6b7280; font-size: 12px;">· tracking started <?php echo esc_html($firstDate); ?></span>
                    <?php endif; ?>
                </h2>
                <span style="font-size: 12px; color: #6b7280;">requests/day, stacked by status</span>
            </div>
            <div class="clockwork-card__body">
                <div style="font-size: 12px; color: #6b7280; margin-bottom: 12px;">
                    Every HTTP request the server answered — page views, images, scripts, API calls, and bot/crawler
                    traffic all included. That's why these totals run far higher than the visitor counts above; they
                    measure server workload, not people.
                </div>
                <div style="overflow-x: auto;">
                    <svg viewBox="0 0 <?php echo (int) $width; ?> <?php echo (int) $height; ?>"
                         preserveAspectRatio="xMidYMid meet"
                         style="width: 100%; height: auto; display: block;"
                         role="img" aria-label="30-day traffic chart">
                        <?php
                        // Y-axis baseline
                        $baselineY = $padTop + $plotH;
                        echo '<line x1="'.$padLeft.'" x2="'.($padLeft + $plotW).'" y1="'.$baselineY.'" y2="'.$baselineY.'" stroke="#d1d5db" stroke-width="1" />';

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

                            $colCenter = $padLeft + ($colW * ($i + 0.5));
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
                            // Date tick labels — every ~5 days to avoid crowding.
                            // NB: format the x coord WITHOUT a thousands
                            // separator — number_format()'s default comma turns
                            // x="1014.80" into x="1,014.80", which SVG parses as
                            // x="1" and slams the label to the far left. This
                            // silently mangled the last two labels on every
                            // 30-day chart (their centers exceed x=1000).
                            if ($i % 5 === 0 || $i === $colCount - 1) {
                                $label = strlen($date) >= 10 ? substr($date, 5) : $date;
                                echo '<text x="'.number_format($colCenter, 2, '.', '').'" y="'.($baselineY + 14).'" text-anchor="middle" font-size="10" fill="#6b7280">'.esc_html($label).'</text>';
                            }
                            echo '</g>';
                            $i++;
                        }

                        // Y-axis ticks — labels + faint horizontal gridlines at
                        // each "nice" boundary (5k, 10k, 15k …). Skip the 0
                        // line gridline because the baseline already draws it.
                        foreach ($tickInfo['ticks'] as $tickValue) {
                            $tickY = $baselineY - (($tickValue / $maxRequests) * $plotH);
                            if ($tickValue > 0) {
                                printf(
                                    '<line x1="%.2f" x2="%.2f" y1="%.2f" y2="%.2f" stroke="#e5e7eb" stroke-width="1" stroke-dasharray="2,3" />',
                                    (float) $padLeft, (float) ($padLeft + $plotW), $tickY, $tickY
                                );
                            }
                            echo '<text x="'.($padLeft - 6).'" y="'.number_format($tickY + 4, 2, '.', '').'" text-anchor="end" font-size="10" fill="#6b7280">'.esc_html($tickValue === 0 ? '0' : self::shortNumber($tickValue)).'</text>';
                        }
                        ?>
                    </svg>
                </div>
                <div style="display: flex; gap: 18px; flex-wrap: wrap; padding: 8px 0 0; font-size: 12px; color: #4b5563;">
                    <?php
                    $legend = [
                        '2xx' => ['color' => $colors['2xx'], 'label' => '2xx', 'desc' => 'Successful requests'],
                        '3xx' => ['color' => $colors['3xx'], 'label' => '3xx', 'desc' => 'Redirects (e.g. HTTP → HTTPS)'],
                        '4xx' => ['color' => $colors['4xx'], 'label' => '4xx', 'desc' => 'Not found / access errors'],
                        '5xx' => ['color' => $colors['5xx'], 'label' => '5xx', 'desc' => 'Server errors'],
                    ];
                    foreach ($legend as $entry) :
                    ?>
                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                            <span style="display: inline-block; width: 10px; height: 10px; background: <?php echo esc_attr($entry['color']); ?>; border-radius: 2px; flex-shrink: 0;"></span>
                            <strong><?php echo esc_html($entry['label']); ?></strong> — <?php echo esc_html($entry['desc']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <p style="margin: 10px 0 0; font-size: 12px; color: #6b7280;">
                    Seeing a spike in 4xx or 5xx errors? <a href="https://clockworkwd.com/support/" target="_blank" rel="noopener">Reach out to Clockwork Web Dev</a> and we can help investigate.
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Renders three Top Paths cards — Pages/Posts, API/Bots, Media Library.
     *
     * The ranked hit list mixes radically different things by default
     * (visitor pages vs. WP internals vs. uploaded files) so we split them
     * into three lists where each is interpretable on its own.
     *
     * Backwards-compat: if the receiver still has a flat list cached from
     * a 1.16.0 push, fold it into Pages/Posts so the page still renders
     * something during the sender-side deploy → backfill window.
     *
     * @param  array<string, mixed>  $report
     */
    private static function renderTopPathsCard(array $report): void
    {
        $top = $report['top_paths'] ?? null;
        $date = (string) ($report['top_paths_date'] ?? '');

        if (! is_array($top) || $top === []) {
            return;
        }

        // Tolerate the legacy flat-list shape — fold into pages.
        if (array_is_list($top)) {
            $top = ['pages' => $top, 'api' => [], 'uploads' => []];
        }

        $sections = [
            ['key' => 'pages',   'title' => 'Pages/Posts',     'sub' => 'Pages and posts your visitors actually viewed.'],
            ['key' => 'api',     'title' => 'API/Bots',        'sub' => 'WordPress internals — admin-ajax, REST, sitemap, robots, theme/plugin assets. Mostly bot traffic.'],
            ['key' => 'uploads', 'title' => 'Media Library',   'sub' => 'Files served from /wp-content/uploads/.'],
        ];

        foreach ($sections as $section) {
            $rows = is_array($top[$section['key']] ?? null) ? $top[$section['key']] : [];
            if ($rows === []) {
                continue;
            }
            self::renderTopPathsBucket($section['title'], $section['sub'], $date, $rows);
        }
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private static function renderTopPathsBucket(string $title, string $sub, string $date, array $rows): void
    {
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__head">
                <h2><?php echo esc_html($title); ?>
                    <?php if ($date !== '') : ?>
                        <span style="font-weight: 400; color: #6b7280; font-size: 12px;">· <?php echo esc_html($date); ?></span>
                    <?php endif; ?>
                </h2>
                <span style="font-size: 12px; color: #6b7280;"><?php echo esc_html($sub); ?></span>
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
                        <?php foreach ($rows as $row) : ?>
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
    private static function renderCaption(array $report, bool $periodSummary = false): void
    {
        $fetched = self::formatTimestamp($report['fetched_at'] ?? null);
        ?>
        <p class="clockwork-meta-line" style="padding: 0 4px;">
            <?php if ($periodSummary) : ?>
                From your host's own traffic stats &middot;
            <?php else : ?>
                From your server's access logs &middot;
                Visits = unique IPs/day, excluding 403s and static assets &middot;
            <?php endif; ?>
            Refreshed nightly<?php if ($fetched) : ?> &middot; Last refresh <?php echo esc_html($fetched); ?><?php endif; ?>
        </p>
        <?php
    }

    /**
     * Pick a "nice" y-axis configuration for the given observed maximum.
     *
     * Step sizes are restricted to the 1-2-5 × 10ⁿ sequence (the standard
     * decimal nicely-divisible scale): 1, 2, 5, 10, 20, 50, 100, 200, 500,
     * 1k, 2k, 5k, 10k, 20k, 50k, 100k, … so labels always read clean.
     *
     * Targets ~4 ticks above zero, rounding the chart top up to the next
     * step boundary so the tallest bar leaves a small sliver of headroom.
     *
     * Examples:
     *   max =  9,200 → step 5,000  → ticks [0, 5k, 10k]
     *   max = 11,000 → step 5,000  → ticks [0, 5k, 10k, 15k]
     *   max =    800 → step   500  → ticks [0, 500, 1000]
     *   max =     50 → step    25  →  not a nice multiplier… falls back to
     *                                 step 20 → ticks [0, 20, 40, 60]
     *
     * @return array{top: int, step: int, ticks: list<int>}
     */
    private static function niceTicks(int $observedMax, int $targetTickCount = 4): array
    {
        if ($observedMax <= 0) {
            return ['top' => 1, 'step' => 1, 'ticks' => [0, 1]];
        }
        $rough = $observedMax / max(1, $targetTickCount);
        $magnitude = (int) max(1, pow(10, floor(log10(max(1, $rough)))));
        $normalized = $rough / $magnitude;
        $multiplier = match (true) {
            $normalized <= 1 => 1,
            $normalized <= 2 => 2,
            $normalized <= 5 => 5,
            default => 10,
        };
        $step = $multiplier * $magnitude;
        $top = (int) (ceil($observedMax / $step) * $step);
        $ticks = [];
        for ($t = 0; $t <= $top; $t += $step) {
            $ticks[] = $t;
        }
        return ['top' => $top, 'step' => $step, 'ticks' => $ticks];
    }

    /**
     * Compact axis labels — 12,400 → 12.4k, 1,200,000 → 1.2M.
     */
    private static function shortNumber(int $n): string
    {
        if ($n >= 1_000_000) {
            return self::trimZero(number_format($n / 1_000_000, 1)).'M';
        }
        if ($n >= 1_000) {
            return self::trimZero(number_format($n / 1_000, 1)).'k';
        }
        return (string) $n;
    }

    /**
     * Drop a trailing ".0" so round values read "300k" / "2M" instead of
     * "300.0k" / "2.0M" — keeps y-axis labels narrow. Non-round values
     * ("1.5k") keep their decimal.
     */
    private static function trimZero(string $formatted): string
    {
        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
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
