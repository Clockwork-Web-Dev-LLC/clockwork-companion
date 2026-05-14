<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\ActionLog\Repository;
use ClockworkCompanion\Admin\Actions\RunSecurityScanAction;
use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\AuthAudit\Repository as AuthAuditRepository;
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
        $latestBlacklist = Repository::latestByActionLog('blacklist');
        $checksumsRunnable = ChecksumsRunner::isAvailable();

        Layout::pageHeader(
            'Security',
            'Checks Clockwork runs against this site to flag domain blacklist hits, malware, and tampering with WordPress core files.'
        );

        self::renderFlash();
        self::renderCarePlanBanner($onCarePlan);

        ?>
        <div class="clockwork-security-grid">
            <?php
            // Hosting-tier card: always active regardless of care-plan state.
            // No Run button — the scan is driven by Clockwork on a daily
            // schedule, and Companion has no outbound channel back to it.
            self::renderCard([
                'icon' => 'list',
                'title' => 'Domain blacklists',
                'subtitle' => 'Checks Spamhaus DBL, URLHaus, and Google Safe Browsing for any sign your domain has been flagged.',
                'cadence' => 'Daily — 02:15 UTC',
                'scan_type' => 'blacklist',
                'tier' => 'hosting',
                'state' => self::cardState('hosting', $onCarePlan, true),
                'latest' => $latestBlacklist,
                'runnable' => false,
                'host_unavailable_note' => null,
            ]);
            self::renderCard([
                'icon' => 'globe',
                'title' => 'Sucuri SiteCheck',
                'subtitle' => 'Remote malware and page-content scan that flags suspicious changes to your homepage.',
                'cadence' => 'Weekly — Mondays 02:00 UTC',
                'scan_type' => 'sitecheck',
                'tier' => 'care-plan',
                'state' => self::cardState('care-plan', $onCarePlan, true),
                'latest' => $latestSitecheck,
                'runnable' => true,
                'host_unavailable_note' => null,
            ]);
            self::renderCard([
                'icon' => 'shield',
                'title' => 'Core file integrity',
                'subtitle' => "Verifies every WordPress core file against WordPress.org's published checksums. Catches tampering Sucuri can't see.",
                'cadence' => 'Daily — 02:30 UTC',
                'scan_type' => 'core_checksums',
                'tier' => 'care-plan',
                'state' => self::cardState('care-plan', $onCarePlan, $checksumsRunnable),
                'latest' => $latestChecksum,
                'runnable' => true,
                'host_unavailable_note' => $checksumsRunnable
                    ? null
                    : "This host can't run core checksum verification — wp-cli isn't reachable and the WordPress HTTP API is blocked. Talk to Clockwork Web Dev.",
            ]);
            ?>
        </div>
        <?php

        self::renderHistorySection();
        self::renderAuthFailuresCard();
        self::renderInlineStyles();
    }

    /**
     * Renders a card on Tools → Clockwork → Security showing recent failed
     * HMAC verifications. Operator-facing visibility into probing/brute-force
     * attempts: 30-fail-per-IP-per-min rate limit silently locks attackers out
     * but historically left no trace. This is that trace.
     *
     * Empty state ("No failures recorded") is the GOOD state — render it as a
     * positive signal, not a "missing data" warning.
     */
    private static function renderAuthFailuresCard(): void
    {
        $count24h = AuthAuditRepository::countSince(86400);
        $count7d = AuthAuditRepository::countSince(86400 * 7);
        $top = AuthAuditRepository::topOffenders(86400 * 7, 5);
        $recent = AuthAuditRepository::recent(20);
        $totalSeen = count($recent);

        ?>
        <div class="clockwork-card" style="margin-top: 16px;">
            <div class="clockwork-card__head">
                <h2>Authentication audit</h2>
                <?php if ($count24h > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--warn">
                        <?php echo (int) $count24h; ?> in last 24h
                    </span>
                <?php elseif ($count7d > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--info">
                        <?php echo (int) $count7d; ?> in last 7d
                    </span>
                <?php else : ?>
                    <span class="clockwork-pill clockwork-pill--ok">
                        <i class="dashicons dashicons-yes"></i> No failures
                    </span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body">
                <p style="margin: 0 0 12px; color: #4b5563;">
                    Failed authentication attempts on the Clockwork REST endpoints.
                    Cryptographic protocol is uncrackable; this catalogues
                    probing, misconfiguration, and stale-secret deployments so
                    Clockwork Web Dev can investigate when the count spikes.
                </p>

                <?php if ($totalSeen === 0) : ?>
                    <div class="clockwork-notice clockwork-notice--ok">
                        <i class="dashicons dashicons-shield-alt"></i>
                        No failed attempts have been recorded. Clockwork Web Dev
                        is the only signed caller of these endpoints.
                    </div>
                <?php else : ?>
                    <?php if (! empty($top)) : ?>
                        <h3 style="margin: 8px 0 4px; font-size: 13px; color: #111827;">Top offenders (last 7 days)</h3>
                        <ul style="margin: 0 0 16px; padding-left: 20px; color: #4b5563; font-size: 13px;">
                            <?php foreach ($top as $offender) : ?>
                                <li>
                                    <strong><?php echo esc_html($offender['ip']); ?></strong>
                                    — <?php echo (int) $offender['count']; ?> attempts,
                                    last seen <?php echo esc_html($offender['last_failed_at']); ?> UTC
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <h3 style="margin: 8px 0 4px; font-size: 13px; color: #111827;">Recent attempts</h3>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>IP</th>
                                <th>Reason</th>
                                <th>Endpoint</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $row) : ?>
                                <?php if (! is_array($row)) {
                                    continue;
                                } ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['failed_at'] ?? '')); ?></td>
                                    <td><span class="clockwork-data"><?php echo esc_html((string) ($row['ip'] ?? '')); ?></span></td>
                                    <td><?php echo esc_html((string) ($row['reason'] ?? '')); ?></td>
                                    <td><span class="clockwork-data" style="font-size: 12px;"><?php echo esc_html((string) ($row['request_method'] ?? '').' '.($row['request_path'] ?? '')); ?></span></td>
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
     * Unified history table beneath the three cards. Pulls every action_log
     * row of `action_type=security_scan` regardless of which scan ran — one
     * row per scan per day, newest first. Mirrors the Backups page's history
     * table style so the two pages feel like siblings.
     */
    /** Page size for the scan history table — kept in sync with ActivityPage. */
    public const HISTORY_PER_PAGE = 25;

    private static function renderHistorySection(): void
    {
        $total = Repository::countByActionType('security_scan');
        $currentPage = Layout::currentPage();
        $offset = ($currentPage - 1) * self::HISTORY_PER_PAGE;
        $rows = Repository::findByActionTypePaged('security_scan', $offset, self::HISTORY_PER_PAGE);

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
                <?php if ($total === 0) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            No scans recorded yet. Once Clockwork Web Dev's daily and weekly checks run,
                            their history will populate here.
                        </div>
                    </div>
                <?php else : ?>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Check</th>
                                <th>Status</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <?php
                                if (! is_array($row)) {
                                    continue;
                                }
                                $details = self::decodeDetails($row['details'] ?? null);
                                $scanTarget = (string) ($row['target'] ?? '');
                                $sucuriBlocked = self::isSucuriBlockedByWaf($row, $scanTarget);
                                $pill = self::historyPill($row, $details, $sucuriBlocked);
                                $summary = (string) ($row['summary'] ?? '');
                                if ($summary === '' && $sucuriBlocked) {
                                    $summary = 'Blocked by site firewall (likely Cloudflare).';
                                } elseif ($summary === '') {
                                    $summary = '—';
                                }
                                $findings = isset($details['findings']) && is_array($details['findings']) ? $details['findings'] : [];
                                $transport = isset($details['transport']) ? (string) $details['transport'] : '';
                                $scannedCount = $details['scanned_files_count'] ?? null;
                                $scanAborted = (bool) ($details['scan_aborted'] ?? false);
                                $abortReason = (string) ($details['abort_reason'] ?? '');
                                $expandable = ! empty($findings) || $scanAborted;
                                ?>
                                <tr>
                                    <td class="mono">
                                        <?php echo esc_html(self::humanTimeAgo((string) ($row['ran_at'] ?? ''))); ?>
                                        <div style="font-size: 11px; color: #6b7280;">
                                            <?php echo esc_html(self::formatTimestampUtc((string) ($row['ran_at'] ?? '')) ?: ''); ?>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html(self::scanTargetLabel($scanTarget)); ?></td>
                                    <td>
                                        <span class="clockwork-pill clockwork-pill--<?php echo esc_attr($pill['variant']); ?>">
                                            <?php echo esc_html($pill['label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($expandable) : ?>
                                            <details class="clockwork-finding-toggle">
                                                <summary><?php echo esc_html($summary); ?></summary>
                                                <div class="clockwork-finding-detail">
                                                    <?php if ($transport !== '' || $scannedCount !== null || $scanAborted) : ?>
                                                        <div class="clockwork-finding-meta">
                                                            <?php if ($transport !== '') : ?>
                                                                <span><strong>Transport:</strong> <span class="mono"><?php echo esc_html($transport); ?></span></span>
                                                            <?php endif; ?>
                                                            <?php if ($scannedCount !== null) : ?>
                                                                <span><strong>Files scanned:</strong> <span class="mono"><?php echo esc_html(number_format((int) $scannedCount)); ?></span></span>
                                                            <?php endif; ?>
                                                            <?php if ($scanAborted) : ?>
                                                                <span class="clockwork-finding-meta__warn">
                                                                    Scan aborted<?php echo $abortReason !== '' ? ': ' . esc_html($abortReason) : ''; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (! empty($findings)) : ?>
                                                        <div class="clockwork-finding-count">
                                                            <?php echo (int) count($findings); ?> finding<?php echo count($findings) === 1 ? '' : 's'; ?>
                                                        </div>
                                                        <ul class="clockwork-finding-list">
                                                            <?php foreach ($findings as $f) : ?>
                                                                <?php
                                                                if (! is_array($f)) {
                                                                    continue;
                                                                }
                                                                $kind = (string) ($f['kind'] ?? 'unknown');
                                                                $path = (string) ($f['path'] ?? '(no path)');
                                                                $evidence = (string) ($f['evidence'] ?? '');
                                                                ?>
                                                                <li>
                                                                    <div class="clockwork-finding-list__head">
                                                                        <span class="clockwork-finding-kind"><?php echo esc_html($kind); ?></span>
                                                                        <span class="mono"><?php echo esc_html($path); ?></span>
                                                                    </div>
                                                                    <?php if ($evidence !== '') : ?>
                                                                        <div class="clockwork-finding-evidence mono"><?php echo esc_html($evidence); ?></div>
                                                                    <?php endif; ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                        <?php else : ?>
                                            <?php echo esc_html($summary); ?>
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
        Layout::renderPagination(
            $total,
            self::HISTORY_PER_PAGE,
            $currentPage,
            ['page' => self::SLUG],
        );
    }

    private static function scanTargetLabel(string $target): string
    {
        return match ($target) {
            'sitecheck' => 'Sucuri SiteCheck',
            'core_checksums' => 'Core integrity',
            'blacklist' => 'Domain blacklists',
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $target)),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $details
     * @return array{variant: string, label: string}
     */
    private static function historyPill(array $row, array $details, bool $sucuriBlocked): array
    {
        if ($sucuriBlocked) {
            return ['variant' => 'muted', 'label' => 'Blocked by firewall'];
        }
        $status = (string) ($details['status'] ?? '');
        if ($status === 'failed' || empty($row['ok'])) {
            return ['variant' => 'warn', 'label' => 'Failed'];
        }
        // Warning-status scans (e.g. `wp_config_recently_modified` finding
        // with no actual malware) must downgrade to yellow even when
        // modified_files_count is non-zero. The agency-side scanner stamps
        // status='warning' on these; render them as Review, not Issues.
        if ($status === 'warning') {
            return ['variant' => 'warn', 'label' => 'Review'];
        }
        $hasIssue = ($status === 'issues_found')
            || ! empty($details['has_malware_hit'])
            || ! empty($details['blacklist_hit'])
            || (int) ($details['modified_files_count'] ?? 0) > 0;
        if ($hasIssue) {
            return ['variant' => 'red', 'label' => 'Issues found'];
        }

        return ['variant' => 'green', 'label' => 'Clean'];
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

    /**
     * Hosting-tier cards are always active (subject to host availability) —
     * the check is a hosting feature, not a care-plan deliverable. Care-plan
     * cards collapse to 'care-plan-off' when the flag is false.
     *
     * @return 'active'|'host-unavailable'|'care-plan-off'
     */
    private static function cardState(string $tier, bool $onCarePlan, bool $availableOnHost): string
    {
        if ($tier === 'hosting') {
            return $availableOnHost ? 'active' : 'host-unavailable';
        }
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
                    <strong>Hosting + care-plan security checks are running.</strong>
                    Daily domain blacklist checks come with your hosting. Weekly malware scans (Sucuri) and daily
                    WordPress core file integrity verification come with your care plan. Use the buttons below to
                    run one now.
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #f59e0b;">
                <div class="clockwork-card__body">
                    <strong>Hosting includes daily blacklist checks. A care plan adds the deeper scans.</strong>
                    Clockwork Web Dev checks every day to make sure your domain isn't flagged on Spamhaus, URLHaus,
                    or Google Safe Browsing — that one's already running. With a care plan, they also run
                    <strong>weekly Sucuri malware/JavaScript-injection scans</strong> and
                    <strong>daily WordPress core file integrity verification</strong> over SSH.
                    Talk to Clockwork Web Dev about adding a care plan.
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
     *   tier: string,
     *   state: string,
     *   latest: ?array<string, mixed>,
     *   runnable: bool,
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

            <?php if ($card['state'] === 'active' && ! empty($card['runnable'])) : ?>
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
                        <?php echo esc_html('Sucuri SiteCheck runs every Monday — scans your homepage for malware, JavaScript injections, and defacement. If anything trips, Clockwork Web Dev gets alerted within minutes.'); ?>
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
                    and your firewall rejects them as bots. The <strong>Domain blacklists</strong> card catches
                    the same blacklist signal directly, and the <strong>Core file integrity</strong> check
                    runs server-side — that's the deeper malware check anyway.
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
                    } elseif ($card['scan_type'] === 'blacklist') {
                        self::renderStat('Blacklisted', ! empty($details['blacklist_hit']) ? 'YES' : 'no');
                        self::renderStat('Result', ucfirst((string) ($details['status'] ?? 'unknown')));
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
        if ($status === 'failed' || empty($latest['ok'])) {
            return ['variant' => 'warn', 'label' => 'Failed'];
        }
        // Same warning carve-out as historyPill() — see comment there.
        if ($status === 'warning') {
            return ['variant' => 'warn', 'label' => 'Review'];
        }
        $hasIssue = ($status === 'issues_found')
            || ! empty($details['has_malware_hit'])
            || ! empty($details['blacklist_hit'])
            || (int) ($details['modified_files_count'] ?? 0) > 0;
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
        if ($name === 'list') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>';
        }

        return '';
    }
}
