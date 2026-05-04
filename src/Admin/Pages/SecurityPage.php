<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\ActionLog\Repository;
use ClockworkCompanion\Admin\Actions\RunSecurityScanAction;
use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\SecurityScans\ChecksumsRunner;

/**
 * Tools → Clockwork → Security admin page.
 *
 * Two-card layout that mirrors the Clockwork dashboard's per-site Security
 * tab — same shape, same fields, same status pills — so a client clicking
 * around their wp-admin sees the same result Aaron sees in his ops tool.
 *
 * Card states:
 *   - active            → care plan ON + scan available on this host
 *   - host-unavailable  → care plan ON but the scan can't run here (e.g. core
 *                         checksums on a host without wp-cli or PHP file
 *                         hashing). Greyed; explanation rendered.
 *   - care-plan-off     → care plan OFF, both cards greyed regardless.
 *
 * Care plan flag is read from the latest action_log row's care_plan_enabled —
 * Clockwork sets this on every push, so the admin page picks up changes
 * automatically without a separate config push channel.
 *
 * Run buttons fan out to admin-post.php → RunSecurityScanAction. Synchronous;
 * the action redirects back here with a flash message in the URL.
 */
class SecurityPage
{
    public const SLUG = 'clockwork-security';

    public static function render(): void
    {
        Layout::render('security', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $onCarePlan = Repository::latestCarePlanFlag();
        $latestSitecheck = Repository::latestByActionLog('sitecheck');
        $latestChecksum = Repository::latestByActionLog('core_checksums');
        $checksumsRunnable = ChecksumsRunner::isAvailable();

        Layout::pageHeader(
            'Security',
            "Daily checks Clockwork runs against this site to flag malware, blacklist hits, and tampering with WordPress core files."
        );

        self::renderFlash();
        self::renderCarePlanBanner($onCarePlan);

        ?>
        <div class="clockwork-security-grid">
            <?php
            self::renderCard([
                'icon' => 'globe',
                'title' => 'Sucuri SiteCheck',
                'subtitle' => 'Remote malware + blacklist scan (replaces ManageWP).',
                'cadence' => 'Weekly — Mondays 02:00 UTC',
                'scan_type' => 'sitecheck',
                'state' => self::cardState($onCarePlan, true),
                'latest' => $latestSitecheck,
                'host_unavailable_note' => null,
            ]);
            self::renderCard([
                'icon' => 'shield',
                'title' => 'Core file integrity',
                'subtitle' => "Verifies every WordPress core file against WordPress.org's published checksums. Catches tampering Sucuri can't see.",
                'cadence' => 'Daily — 02:30 UTC',
                'scan_type' => 'core_checksums',
                'state' => self::cardState($onCarePlan, $checksumsRunnable),
                'latest' => $latestChecksum,
                'host_unavailable_note' => $checksumsRunnable
                    ? null
                    : "This host can't run core checksum verification — wp-cli isn't reachable and the WordPress HTTP API is blocked. Talk to your hosting provider.",
            ]);
            ?>
        </div>
        <?php

        self::renderInlineStyles();
    }

    /**
     * @return 'active'|'host-unavailable'|'care-plan-off'
     */
    private static function cardState(bool $onCarePlan, bool $availableOnHost): string
    {
        if (! $onCarePlan) {
            return 'care-plan-off';
        }
        if (! $availableOnHost) {
            return 'host-unavailable';
        }

        return 'active';
    }

    private static function renderFlash(): void
    {
        $msg = isset($_GET['flash']) ? (string) $_GET['flash'] : '';
        if ($msg === '') {
            return;
        }
        ?>
        <div class="clockwork-card" style="border-left: 4px solid #65a30d;">
            <div class="clockwork-card__body">
                <strong>✓</strong> <?php echo esc_html($msg); ?>
            </div>
        </div>
        <?php
    }

    private static function renderCarePlanBanner(bool $onCarePlan): void
    {
        if ($onCarePlan) {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #65a30d;">
                <div class="clockwork-card__body">
                    <strong>Daily security scans are part of your care plan.</strong>
                    Your hosting provider runs the scans below on a schedule. Use the buttons to run one now.
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #f59e0b;">
                <div class="clockwork-card__body">
                    <strong>You're on hosting only — add a care plan to unlock these.</strong>
                    With a care plan, your hosting provider runs <strong>weekly malware + blacklist scans</strong> via Sucuri SiteCheck
                    and <strong>daily WordPress core file integrity verification</strong> over SSH. If anything gets flagged, they're alerted
                    so it can be fixed before it affects your visitors. Talk to your hosting provider about adding a care plan.
                </div>
            </div>
            <?php
        }
    }

    /**
     * @param  array{
     *   icon: string,
     *   title: string,
     *   subtitle: string,
     *   cadence: string,
     *   scan_type: string,
     *   state: string,
     *   latest: ?array<string, mixed>,
     *   host_unavailable_note: ?string,
     * }  $card
     */
    private static function renderCard(array $card): void
    {
        $disabled = $card['state'] !== 'active';
        // Off-plan cards never expose historical scan data — even if rows
        // exist from a previous care-plan period, hiding them keeps the
        // "you can't see the data unless you're on a care plan" promise
        // visually consistent with the "Disabled" pill.
        $latest = $card['state'] === 'care-plan-off' ? null : $card['latest'];
        $details = self::decodeDetails($latest['details'] ?? null);
        $pill = self::statusPill($latest, $details, $card['state'], (string) $card['scan_type']);
        $sucuriBlocked = self::isSucuriBlockedByWaf($latest, (string) $card['scan_type']);

        ?>
        <div class="clockwork-scan-card <?php echo $disabled ? 'is-disabled' : ''; ?>">
            <div class="clockwork-scan-card__head">
                <div class="clockwork-scan-card__title">
                    <span class="clockwork-scan-card__icon"><?php echo self::iconSvg($card['icon']); ?></span>
                    <h2><?php echo esc_html($card['title']); ?></h2>
                </div>
                <span class="clockwork-pill clockwork-pill--<?php echo esc_attr($pill['variant']); ?>">
                    <?php echo esc_html($pill['label']); ?>
                </span>
            </div>
            <p class="clockwork-scan-card__subtitle">
                <?php echo esc_html($card['subtitle']); ?>
            </p>

            <?php if ($card['state'] === 'active') : ?>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="clockwork-scan-card__run">
                    <input type="hidden" name="action" value="<?php echo esc_attr(RunSecurityScanAction::ACTION_HOOK); ?>">
                    <input type="hidden" name="scan_type" value="<?php echo esc_attr($card['scan_type']); ?>">
                    <?php wp_nonce_field(RunSecurityScanAction::NONCE_ACTION); ?>
                    <button type="submit" class="button">
                        <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                        Run scan now
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($card['state'] === 'host-unavailable' && $card['host_unavailable_note']) : ?>
                <p class="clockwork-scan-card__note">
                    <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                    <?php echo esc_html($card['host_unavailable_note']); ?>
                </p>
            <?php endif; ?>

            <?php if ($card['state'] === 'care-plan-off') : ?>
                <p class="clockwork-scan-card__empty">
                    <strong>Included with a care plan.</strong>
                    <?php if ($card['scan_type'] === 'sitecheck') : ?>
                        <?php echo esc_html('Sucuri SiteCheck runs every Monday — scans your homepage for malware, JavaScript injections, defacement, and checks 30+ blacklists. If anything trips, your hosting provider gets alerted within minutes.'); ?>
                    <?php else : ?>
                        <?php echo esc_html('Every WordPress core file is verified against WordPress.org\'s published checksums daily. Catches PHP backdoors, modified core files, and shells dropped into wp-includes — the kind of malware Sucuri\'s public scan can\'t see.'); ?>
                    <?php endif; ?>
                </p>
            <?php elseif ($latest === null) : ?>
                <p class="clockwork-scan-card__empty">
                    No scan recorded yet. The next scheduled run is <strong><?php echo esc_html($card['cadence']); ?></strong>.
                </p>
            <?php elseif ($sucuriBlocked) : ?>
                <p class="clockwork-scan-card__note">
                    <strong>Your site's firewall (likely Cloudflare) is blocking Sucuri's external scanner.</strong>
                    This is normal for sites behind a WAF — Sucuri tries to fetch the homepage from their servers
                    and your firewall rejects them as bots. The <strong>Core file integrity</strong> check below
                    runs server-side and isn't affected; that's the deeper malware check anyway.
                </p>
                <dl class="clockwork-scan-card__stats">
                    <?php
                    self::renderStat('Last attempt', self::humanTimeAgo((string) ($latest['ran_at'] ?? '')));
                    self::renderStat('Response', (string) ($latest['error'] ?? '—'));
                    ?>
                </dl>
            <?php else : ?>
                <p class="clockwork-scan-card__summary">
                    <?php echo esc_html((string) ($latest['summary'] ?? '')); ?>
                </p>

                <dl class="clockwork-scan-card__stats">
                    <?php
                    self::renderStat('Scanned', self::humanTimeAgo((string) ($latest['ran_at'] ?? '')));
                    self::renderStat('Took', isset($latest['elapsed_ms']) ? number_format((int) $latest['elapsed_ms']).' ms' : '—');

                    if ($card['scan_type'] === 'sitecheck') {
                        self::renderStat('Malware', ! empty($details['has_malware_hit']) ? 'YES' : 'no');
                        self::renderStat('Blacklist', ! empty($details['blacklist_hit']) ? 'YES' : 'no');
                    } else {
                        $modified = (int) ($details['modified_files_count'] ?? 0);
                        $version = (string) ($details['wp_version'] ?? '');
                        self::renderStat('Files flagged', (string) $modified);
                        self::renderStat('WP version', $version !== '' ? $version : '—');
                    }
                    ?>
                </dl>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderStat(string $label, string $value): void
    {
        ?>
        <div class="clockwork-scan-card__stat">
            <dt><?php echo esc_html($label); ?></dt>
            <dd><?php echo esc_html($value); ?></dd>
        </div>
        <?php
    }

    /**
     * Sucuri's external scanner gets blocked by Cloudflare's WAF on CF-proxied
     * sites, returning a 403. That's a configuration limitation, not a security
     * failure — we don't have access to the site's cloudflare_state on the
     * Companion side, so we infer from the error string. A 403/Forbidden on a
     * sitecheck scan is overwhelmingly a WAF block (CF most often).
     *
     * @param  array<string, mixed>|null  $latest
     */
    private static function isSucuriBlockedByWaf(?array $latest, string $scanType): bool
    {
        if ($latest === null || $scanType !== 'sitecheck') {
            return false;
        }
        if (! empty($latest['ok'])) {
            return false;
        }
        $err = strtolower((string) ($latest['error'] ?? ''));
        if ($err === '') {
            return false;
        }

        return str_contains($err, '403') || str_contains($err, 'forbidden');
    }

    /**
     * @param  array<string, mixed>|null  $latest
     * @param  array<string, mixed>  $details
     * @return array{variant: string, label: string}
     */
    private static function statusPill(?array $latest, array $details, string $cardState, string $scanType = ''): array
    {
        if ($cardState === 'care-plan-off') {
            return ['variant' => 'muted', 'label' => 'Disabled'];
        }
        if ($cardState === 'host-unavailable') {
            return ['variant' => 'muted', 'label' => 'Not available'];
        }
        if ($latest === null) {
            return ['variant' => 'muted', 'label' => 'Not yet scanned'];
        }
        if (self::isSucuriBlockedByWaf($latest, $scanType)) {
            return ['variant' => 'muted', 'label' => 'Blocked by firewall'];
        }
        $status = (string) ($details['status'] ?? '');
        $hasIssue = ($status === 'issues_found')
            || ! empty($details['has_malware_hit'])
            || ! empty($details['blacklist_hit'])
            || (int) ($details['modified_files_count'] ?? 0) > 0;
        if ($status === 'failed' || empty($latest['ok'])) {
            return ['variant' => 'warn', 'label' => 'Failed'];
        }
        if ($hasIssue) {
            return ['variant' => 'red', 'label' => 'Issues found'];
        }

        return ['variant' => 'green', 'label' => 'Clean'];
    }

    /**
     * Inline minimal CSS so we don't have to ship a new admin.css release just
     * for two cards. Mirrors the dashboard's visual language as closely as
     * WP-admin's contrasting CSS lets us.
     */
    private static function renderInlineStyles(): void
    {
        ?>
        <style>
        .clockwork-security-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        .clockwork-scan-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: opacity 0.15s;
        }
        .clockwork-scan-card.is-disabled {
            opacity: 0.55;
            background: #f9fafb;
        }
        .clockwork-scan-card__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .clockwork-scan-card__title {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .clockwork-scan-card__title h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        .clockwork-scan-card__icon {
            display: inline-flex;
            color: #6b7280;
        }
        .clockwork-scan-card__icon svg {
            width: 20px;
            height: 20px;
        }
        .clockwork-scan-card__subtitle {
            color: #6b7280;
            margin: 0;
            font-size: 13px;
        }
        .clockwork-scan-card__run {
            margin: 0;
        }
        .clockwork-scan-card__summary {
            margin: 0;
            color: #111827;
            font-size: 14px;
        }
        .clockwork-scan-card__empty {
            margin: 0;
            color: #6b7280;
            font-size: 13px;
        }
        .clockwork-scan-card__note {
            margin: 0;
            padding: 10px 12px;
            background: #f3f4f6;
            border-radius: 6px;
            font-size: 12px;
            color: #4b5563;
        }
        .clockwork-scan-card__stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 0;
        }
        .clockwork-scan-card__stat dt {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            margin-bottom: 2px;
        }
        .clockwork-scan-card__stat dd {
            margin: 0;
            font-weight: 600;
            color: #111827;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 14px;
        }
        .clockwork-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .clockwork-pill--green { background: #dcfce7; color: #15803d; }
        .clockwork-pill--red { background: #fee2e2; color: #b91c1c; }
        .clockwork-pill--warn { background: #fef3c7; color: #92400e; }
        .clockwork-pill--muted { background: #f3f4f6; color: #6b7280; }
        </style>
        <?php
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

    private static function iconSvg(string $name): string
    {
        // Minimal inline SVGs so we don't depend on a font-icon kit. Stroke-based,
        // currentColor — pick up the muted gray from the parent .clockwork-scan-card__icon.
        if ($name === 'globe') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>';
        }
        if ($name === 'shield') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>';
        }

        return '';
    }
}
