<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\ActionLog\Repository;
use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\WhiteLabel\WhiteLabel;

/**
 * Tools → Clockwork → Uptime admin page.
 *
 * Mirrors the ManageWP "Uptime Activity" layout: big UP/DOWN circle, overall
 * uptime hero with current up-since duration, three "uptime overview" cards
 * (24h / 7d / 30d), and a "Latest events" table.
 *
 * **Data source.** Reads exclusively from the local `wp_clockwork_action_log`
 * mirror table — Clockwork pushes every `uptime_transition` event there as
 * part of the existing ActionLogger pipeline. We don't make outbound calls;
 * the page works fully offline relative to the agency.
 *
 * **Hosting-tier feature.** Uptime monitoring runs for every site regardless
 * of care plan, so the page is never gated. Sites with no event history yet
 * get a friendly "monitoring will populate within 24 hours" message rather
 * than scary empty data.
 */
class UptimePage
{
    public const SLUG = 'clockwork-uptime';

    private const ACTION_TYPE = 'uptime_transition';

    public static function render(): void
    {
        Layout::render('uptime', [self::class, 'renderBody']);
    }

    /**
     * Compact status for the wp-admin dashboard widget — current up/down
     * state plus 30-day uptime %. Reuses the same algorithm as the Uptime
     * Activity page so the numbers never disagree with the full page.
     *
     * @return array{state: string, pct30: ?float, hasHistory: bool}
     */
    public static function summary(): array
    {
        $events = Repository::findByActionType(self::ACTION_TYPE, 500);
        $eventsAsc = array_reverse($events);
        $now = time();

        return [
            'state' => self::deriveCurrentState($events[0] ?? null),
            'pct30' => self::uptimePercent($eventsAsc, $now - (86400 * 30), $now),
            'hasHistory' => $events !== [],
        ];
    }

    public static function renderBody(): void
    {
        Layout::pageHeader(
            'Uptime',
            WhiteLabel::getAuthorName() . ' probes this site every few minutes and tracks how often it answers. Big drops trigger an internal ' . WhiteLabel::getAuthorName() . ' alert immediately.'
        );

        $view = self::resolveView();
        self::renderSubTabs($view);

        if ($view === 'settings') {
            self::renderSettingsView();
        } else {
            self::renderActivityView();
        }

        self::renderInlineStyles();
    }

    private static function renderActivityView(): void
    {
        $events = Repository::findByActionType(self::ACTION_TYPE, 500);
        // Repository returns DESC. Walks need ASC for the algorithm.
        $eventsAsc = array_reverse($events);

        $latest = $events[0] ?? null;
        $now = time();
        $currentState = self::deriveCurrentState($latest);
        $latestDowntime = self::deriveLatestDowntime($events);
        $hasAnyHistory = ! empty($events);

        $stats24h = self::uptimePercent($eventsAsc, $now - 86400, $now);
        $stats7d = self::uptimePercent($eventsAsc, $now - (86400 * 7), $now);
        $stats30d = self::uptimePercent($eventsAsc, $now - (86400 * 30), $now);
        $statsAll = self::uptimePercent($eventsAsc, self::earliestEventTs($eventsAsc, $now), $now);

        self::renderHero($currentState, $latest, $statsAll, $latestDowntime, $now, $hasAnyHistory);
        self::renderOverview($stats24h, $stats7d, $stats30d);
        self::renderEvents($events, $now);
    }

    private static function resolveView(): string
    {
        $raw = isset($_GET['view']) ? (string) $_GET['view'] : 'activity';
        return $raw === 'settings' ? 'settings' : 'activity';
    }

    private static function renderSubTabs(string $active): void
    {
        $tabs = [
            'activity' => 'Uptime Activity',
            'settings' => 'About',
        ];
        ?>
        <div class="clockwork-uptime-subtabs">
            <?php foreach ($tabs as $slug => $label) : ?>
                <?php
                $url = add_query_arg(['page' => self::SLUG, 'view' => $slug], admin_url('admin.php'));
                $isActive = $active === $slug;
                ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="clockwork-uptime-subtab <?php echo $isActive ? 'clockwork-uptime-subtab--active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Settings view — read-only. The probe interval and failure threshold
     * live on the agency side. This page just explains what monitoring does
     * and how to reach the agency if changes are needed.
     */
    private static function renderSettingsView(): void
    {
        ?>
        <div class="clockwork-card" style="margin-top: 16px;">
            <div class="clockwork-card__head">
                <h2>How monitoring works</h2>
            </div>
            <div class="clockwork-card__body">
                <dl class="clockwork-uptime-defs">
                    <dt>What we check</dt>
                    <dd>Every few minutes, <?php echo esc_html(WhiteLabel::getAuthorName()); ?> sends a request to <strong>https://<?php echo esc_html(self::currentHost()); ?>/</strong> and verifies it answers with a normal page.</dd>

                    <dt>What counts as "down"</dt>
                    <dd>A page that doesn't load — server errors, timeouts, DNS failures, or anything other than a successful response. Cloudflare or WAF challenges that block our checker are flagged separately so they don't count as real outages.</dd>

                    <dt>Probe frequency</dt>
                    <dd>Every 5 minutes by default. <?php echo esc_html(WhiteLabel::getAuthorName()); ?> can dial this up to once a minute or down to every 15 minutes globally — <button type="button" class="cwk-support-trigger cwk-link-btn">contact us</button> if you want a specific cadence on this site.</dd>

                    <dt>Alert threshold</dt>
                    <dd>Two consecutive failures (about 10 minutes of real downtime) before an alert is fired. Recovery is instant — the moment your site comes back, the alert clears.</dd>

                    <dt>Where alerts go</dt>
                    <dd><?php echo esc_html(WhiteLabel::getAuthorName()); ?>'s internal team chat. They get a notification with the failure reason and a link straight to your site, so they can investigate before you ever notice.</dd>

                    <dt>Want this changed?</dt>
                    <dd>The schedule, threshold, and notification routing are all controlled by <?php echo esc_html(WhiteLabel::getAuthorName()); ?>. <button type="button" class="cwk-support-trigger cwk-link-btn">Reach out to us</button> and we can adjust things on our end.</dd>
                </dl>
            </div>
        </div>
        <?php
    }

    private static function currentHost(): string
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return is_string($host) ? $host : 'your site';
    }

    /**
     * @param  array<string, mixed>|null  $latest
     */
    private static function renderHero(string $state, ?array $latest, ?float $allPct, ?array $latestDowntime, int $now, bool $hasAnyHistory): void
    {
        $isUp = $state === 'up';
        $isDown = $state === 'down';
        $circleClass = match ($state) {
            'up' => 'clockwork-up-circle--green',
            'down' => 'clockwork-up-circle--red',
            default => 'clockwork-up-circle--muted',
        };
        $circleLabel = match ($state) {
            'up' => 'UP',
            'down' => 'DOWN',
            default => '—',
        };

        $heroDuration = self::deriveHeroDuration($state, $latest, $now);

        ?>
        <div class="clockwork-up-hero" style="margin-top: 16px;">
            <div class="clockwork-up-circle <?php echo esc_attr($circleClass); ?>">
                <span><?php echo esc_html($circleLabel); ?></span>
            </div>
            <div class="clockwork-up-summary">
                <div class="clockwork-up-summary__row">
                    <strong>
                        <?php if ($allPct !== null) : ?>
                            Overall uptime is <span class="clockwork-up-summary__pct"><?php echo esc_html(number_format($allPct, 2)); ?>%</span>
                        <?php elseif ($hasAnyHistory) : ?>
                            <span class="clockwork-up-summary__pct">100%</span> uptime — no downtime recorded yet.
                        <?php else : ?>
                            Monitoring is active. <?php echo esc_html(WhiteLabel::getAuthorName()); ?> has been probing this site and hasn't seen any issues.
                        <?php endif; ?>
                    </strong>
                </div>
                <?php if ($heroDuration !== null) : ?>
                    <div class="clockwork-up-summary__row clockwork-up-summary__sub">
                        <?php echo esc_html($heroDuration); ?>
                    </div>
                <?php /* No history at all — give a clear explanation rather than a blank line. */ ?>
                <?php elseif (! $hasAnyHistory) : ?>
                    <div class="clockwork-up-summary__row clockwork-up-summary__sub">
                        Activity will appear here as soon as your site has any state changes (downtime, recovery).
                    </div>
                <?php endif; ?>
                <?php if ($latestDowntime !== null) : ?>
                    <div class="clockwork-up-summary__row clockwork-up-summary__divider">
                        <strong>Latest downtime:</strong>
                        <?php echo esc_html(self::formatTimestampUtc($latestDowntime['at']) ?: '—'); ?>,
                        lasted for <?php echo esc_html(self::formatDuration($latestDowntime['duration_sec'])); ?>
                    </div>
                <?php elseif ($hasAnyHistory) : ?>
                    <div class="clockwork-up-summary__row clockwork-up-summary__divider clockwork-up-summary__sub">
                        <i class="dashicons dashicons-yes-alt" style="color: #16a34a; font-size: 14px; vertical-align: text-bottom;"></i>
                        No downtime recorded — your site has been running smoothly.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param  ?float  $u24
     * @param  ?float  $u7d
     * @param  ?float  $u30
     */
    private static function renderOverview(?float $u24, ?float $u7d, ?float $u30): void
    {
        $colorClass = function (?float $pct): string {
            if ($pct === null) {
                return 'clockwork-up-stat--muted';
            }
            if ($pct >= 99.9) {
                return 'clockwork-up-stat--green';
            }
            if ($pct >= 99.0) {
                return 'clockwork-up-stat--yellow';
            }
            return 'clockwork-up-stat--red';
        };
        $fmt = fn (?float $pct) => $pct === null ? '—' : number_format($pct, 2).'%';

        ?>
        <h2 class="clockwork-up-section-title">Uptime overview</h2>
        <div class="clockwork-up-overview">
            <div class="clockwork-up-stat <?php echo esc_attr($colorClass($u24)); ?>">
                <span class="clockwork-up-stat__pct"><?php echo esc_html($fmt($u24)); ?></span>
                <span class="clockwork-up-stat__label">(last 24 hours)</span>
            </div>
            <div class="clockwork-up-stat <?php echo esc_attr($colorClass($u7d)); ?>">
                <span class="clockwork-up-stat__pct"><?php echo esc_html($fmt($u7d)); ?></span>
                <span class="clockwork-up-stat__label">(last 7 days)</span>
            </div>
            <div class="clockwork-up-stat <?php echo esc_attr($colorClass($u30)); ?>">
                <span class="clockwork-up-stat__pct"><?php echo esc_html($fmt($u30)); ?></span>
                <span class="clockwork-up-stat__label">(last 30 days)</span>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private static function renderEvents(array $events, int $now): void
    {
        ?>
        <h2 class="clockwork-up-section-title">Latest events</h2>
        <div class="clockwork-card">
            <div class="clockwork-card__body clockwork-card__body--tight">
                <?php if ($events === []) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            No transition events recorded yet. <?php echo esc_html(WhiteLabel::getAuthorName()); ?>'s monitor probes
                            every few minutes; the first up-confirmation usually arrives within 24 hours
                            of installation.
                        </div>
                    </div>
                <?php else : ?>
                    <?php
                    $totalEvents = count($events);
                    $initialCap = 30;
                    $hasMore = $totalEvents > $initialCap;
                    ?>
                    <table class="clockwork-table" id="clockwork-uptime-events">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Detail</th>
                                <th>When &amp; duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $i => $row) : ?>
                                <?php
                                if (! is_array($row)) {
                                    continue;
                                }
                                $isUp = ($row['target'] ?? '') === 'up';
                                $arrow = $isUp ? '↑' : '↓';
                                $arrowClass = $isUp ? 'clockwork-up-arrow--up' : 'clockwork-up-arrow--down';
                                $label = $isUp ? 'Up' : 'Down';
                                $details = self::decodeDetails($row['details'] ?? null);
                                $detailText = self::formatEventDetail($isUp, $details, (string) ($row['error'] ?? ''));

                                $ranAt = (string) ($row['ran_at'] ?? '');
                                $ts = $ranAt !== '' ? strtotime($ranAt.' UTC') : false;
                                $whenAbs = $ts !== false ? wp_date('Y-m-d, H:i:s', $ts) : '—';
                                $newerEvent = $i > 0 ? $events[$i - 1] : null;
                                $durationSec = self::durationToNext($ts, $newerEvent, $now);
                                $hidden = $i >= $initialCap;
                                ?>
                                <tr <?php echo $hidden ? 'class="clockwork-uptime-event-extra" style="display:none;"' : ''; ?>>
                                    <td>
                                        <span class="clockwork-up-arrow <?php echo esc_attr($arrowClass); ?>"><?php echo esc_html($arrow); ?> <?php echo esc_html($label); ?></span>
                                    </td>
                                    <td><?php echo esc_html($detailText); ?></td>
                                    <td><?php echo esc_html($whenAbs); ?> <?php echo $durationSec !== null ? 'for '.esc_html(self::formatDuration($durationSec)) : ''; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($hasMore) : ?>
                        <div style="padding: 12px 20px; text-align: center;">
                            <button type="button"
                                    class="button-link clockwork-uptime-show-more"
                                    data-shown="<?php echo (int) $initialCap; ?>"
                                    data-total="<?php echo (int) $totalEvents; ?>">
                                Show <?php echo (int) ($totalEvents - $initialCap); ?> more events…
                            </button>
                        </div>
                        <script>
                        (function(){
                            var btn = document.querySelector('.clockwork-uptime-show-more');
                            if (!btn) return;
                            btn.addEventListener('click', function(e){
                                e.preventDefault();
                                document.querySelectorAll('#clockwork-uptime-events .clockwork-uptime-event-extra').forEach(function(tr){
                                    tr.style.display = '';
                                });
                                btn.parentNode.removeChild(btn);
                            });
                        })();
                        </script>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ---- algorithm helpers ---- */

    /**
     * @param  array<string, mixed>|null  $latest
     */
    private static function deriveCurrentState(?array $latest): string
    {
        if ($latest === null) {
            return 'unknown';
        }
        $target = (string) ($latest['target'] ?? '');
        if ($target === 'up' || $target === 'down') {
            return $target;
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>|null  $latest
     */
    private static function deriveHeroDuration(string $state, ?array $latest, int $now): ?string
    {
        if ($latest === null || ($state !== 'up' && $state !== 'down')) {
            return null;
        }
        $ranAt = (string) ($latest['ran_at'] ?? '');
        $ts = $ranAt !== '' ? strtotime($ranAt.' UTC') : false;
        if ($ts === false) {
            return null;
        }
        $diff = max(0, $now - $ts);
        $word = $state === 'up' ? 'Up' : 'Down';

        return "{$word} for ".self::formatDuration($diff);
    }

    /**
     * Most recent down→up cycle, returning the down timestamp + how long it lasted.
     *
     * @param  array<int, array<string, mixed>>  $eventsDesc
     * @return ?array{at: int, duration_sec: int}
     */
    private static function deriveLatestDowntime(array $eventsDesc): ?array
    {
        // Walk newest-first looking for the most recent 'up' event. The 'down'
        // event immediately *before* it (older in time, later in DESC array)
        // is the start of that downtime window.
        foreach ($eventsDesc as $i => $event) {
            if (! is_array($event) || ($event['target'] ?? '') !== 'up') {
                continue;
            }
            $upTs = strtotime(((string) ($event['ran_at'] ?? '')).' UTC');
            if ($upTs === false) {
                continue;
            }
            // Find the down event right before it.
            for ($j = $i + 1; $j < count($eventsDesc); $j++) {
                $candidate = $eventsDesc[$j] ?? null;
                if (! is_array($candidate) || ($candidate['target'] ?? '') !== 'down') {
                    continue;
                }
                $downTs = strtotime(((string) ($candidate['ran_at'] ?? '')).' UTC');
                if ($downTs === false || $downTs >= $upTs) {
                    continue;
                }

                return ['at' => $downTs, 'duration_sec' => max(0, $upTs - $downTs)];
            }
        }

        return null;
    }

    /**
     * Compute uptime % over [startTs, endTs] from an ASCENDING event list.
     * Same algorithm as Clockwork's UptimeStatsCalculator — walk events,
     * track cursor state, sum down intervals. State at window-start defaults
     * to 'up' (optimistic — pessimism would punish brand-new sites with no
     * history).
     *
     * @param  array<int, array<string, mixed>>  $eventsAsc
     */
    private static function uptimePercent(array $eventsAsc, int $startTs, int $endTs): ?float
    {
        $totalSec = max(1, $endTs - $startTs);

        $cursorState = 'up';
        // Find prior-to-window state from the newest event before $startTs.
        foreach ($eventsAsc as $event) {
            if (! is_array($event)) {
                continue;
            }
            $ts = strtotime(((string) ($event['ran_at'] ?? '')).' UTC');
            if ($ts === false || $ts >= $startTs) {
                break;
            }
            $cursorState = ($event['target'] ?? '') === 'up' ? 'up' : 'down';
        }

        $cursorAt = $startTs;
        $downtimeSec = 0;
        $sawAnyEvent = false;

        foreach ($eventsAsc as $event) {
            if (! is_array($event)) {
                continue;
            }
            $ts = strtotime(((string) ($event['ran_at'] ?? '')).' UTC');
            if ($ts === false || $ts < $startTs) {
                continue;
            }
            if ($ts > $endTs) {
                break;
            }
            $sawAnyEvent = true;
            if ($cursorState === 'down') {
                $downtimeSec += max(0, $ts - $cursorAt);
            }
            $cursorState = ($event['target'] ?? '') === 'up' ? 'up' : 'down';
            $cursorAt = $ts;
        }

        if ($cursorState === 'down') {
            $downtimeSec += max(0, $endTs - $cursorAt);
        }

        // No event history at all (and we defaulted to 'up') — return null
        // rather than a misleading 100%.
        if (! $sawAnyEvent && $eventsAsc === []) {
            return null;
        }

        $uptimeSec = max(0, $totalSec - $downtimeSec);

        return round(($uptimeSec / $totalSec) * 100, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventsAsc
     */
    private static function earliestEventTs(array $eventsAsc, int $fallbackEnd): int
    {
        foreach ($eventsAsc as $event) {
            if (! is_array($event)) {
                continue;
            }
            $ts = strtotime(((string) ($event['ran_at'] ?? '')).' UTC');
            if ($ts !== false) {
                return $ts;
            }
        }

        return $fallbackEnd - 86400;
    }

    /**
     * @param  array<string, mixed>|null  $newerEvent
     */
    private static function durationToNext(int|false $thisTs, ?array $newerEvent, int $now): ?int
    {
        if ($thisTs === false) {
            return null;
        }
        if ($newerEvent === null) {
            // Most recent event — duration runs to "now".
            return max(0, $now - $thisTs);
        }
        $newerTs = strtotime(((string) ($newerEvent['ran_at'] ?? '')).' UTC');
        if ($newerTs === false) {
            return null;
        }

        return max(0, $newerTs - $thisTs);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function formatEventDetail(bool $isUp, array $details, string $error): string
    {
        if ($isUp) {
            return 'Everything is ok';
        }
        if ($error !== '') {
            return $error;
        }
        $code = $details['status_code'] ?? null;
        if (is_numeric($code)) {
            return 'HTTP '.((int) $code);
        }

        return 'Unreachable';
    }

    private static function formatDuration(int $sec): string
    {
        if ($sec < 60) {
            return "{$sec}s";
        }
        $m = (int) floor($sec / 60);
        if ($m < 60) {
            $rem = $sec % 60;
            return $rem > 0 ? "{$m}m {$rem}s" : "{$m}m";
        }
        $h = (int) floor($m / 60);
        $remM = $m % 60;
        if ($h < 24) {
            return $remM > 0 ? "{$h}h {$remM}m" : "{$h}h";
        }
        $d = (int) floor($h / 24);
        $remH = $h % 24;

        return $remH > 0 ? "{$d}d {$remH}h" : "{$d}d";
    }

    private static function formatTimestampUtc(int $ts): ?string
    {
        if ($ts <= 0) {
            return null;
        }

        return wp_date('Y-m-d, H:i:s', $ts);
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

    private static function renderInlineStyles(): void
    {
        ?>
        <style>
        .clockwork-up-hero {
            display: flex;
            align-items: stretch;
            gap: 24px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 28px;
        }
        .clockwork-up-circle {
            flex-shrink: 0;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            border: 8px solid;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-weight: 700;
            font-size: 24px;
            letter-spacing: 0.05em;
            background: #fff;
        }
        .clockwork-up-circle--green { border-color: #16a34a; color: #15803d; }
        .clockwork-up-circle--red { border-color: #dc2626; color: #b91c1c; }
        .clockwork-up-circle--muted { border-color: #d1d5db; color: #6b7280; }
        .clockwork-up-summary {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 6px;
            padding: 16px 20px;
            background: #f9fafb;
            border-radius: 6px;
        }
        .clockwork-up-summary__row {
            font-size: 14px;
            color: #111827;
        }
        .clockwork-up-summary__sub {
            color: #6b7280;
            font-size: 13px;
        }
        .clockwork-up-summary__divider {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
            font-size: 13px;
            color: #4b5563;
        }
        .clockwork-up-summary__pct {
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .clockwork-up-section-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin: 24px 0 12px;
        }
        .clockwork-up-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .clockwork-up-stat {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 24px 16px;
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .clockwork-up-stat__pct {
            font-size: 32px;
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .clockwork-up-stat__label {
            color: #6b7280;
            font-size: 14px;
        }
        .clockwork-up-stat--green .clockwork-up-stat__pct { color: #16a34a; }
        .clockwork-up-stat--yellow .clockwork-up-stat__pct { color: #ca8a04; }
        .clockwork-up-stat--red .clockwork-up-stat__pct { color: #dc2626; }
        .clockwork-up-stat--muted .clockwork-up-stat__pct { color: #6b7280; }
        .clockwork-up-arrow {
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .clockwork-up-arrow--up { color: #16a34a; }
        .clockwork-up-arrow--down { color: #dc2626; }

        .clockwork-uptime-subtabs {
            display: flex;
            gap: 24px;
            border-bottom: 1px solid #e5e7eb;
            margin-top: 8px;
        }
        .clockwork-uptime-subtab {
            padding: 12px 4px;
            color: #6b7280;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
        }
        .clockwork-uptime-subtab:hover {
            color: #111827;
        }
        .clockwork-uptime-subtab--active {
            color: #2271b1;
            border-bottom-color: #2271b1;
        }

        .clockwork-uptime-defs {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 14px 24px;
            margin: 0;
        }
        .clockwork-uptime-defs dt {
            font-weight: 600;
            color: #111827;
            font-size: 13px;
        }
        .clockwork-uptime-defs dd {
            margin: 0;
            color: #4b5563;
            font-size: 13px;
            line-height: 1.5;
        }
        @media (max-width: 600px) {
            .clockwork-uptime-defs {
                grid-template-columns: 1fr;
                gap: 4px 0;
            }
            .clockwork-uptime-defs dd {
                margin-bottom: 12px;
            }
        }
        </style>
        <?php
    }
}
