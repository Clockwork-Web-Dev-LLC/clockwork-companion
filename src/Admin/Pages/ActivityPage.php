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
 * Care-plan banner at the top: green ("included in your plan") if the most
 * recent entry has care_plan_enabled=1, neutral ("billable as ad-hoc work")
 * otherwise. Reads the latest flag rather than caching it separately so the
 * page reflects the current Clockwork-side state without an extra option.
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

    public static function renderBody(): void
    {
        $month = isset($_GET['month']) ? (string) $_GET['month'] : '';
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = gmdate('Y-m');
        }

        $start = $month . '-01 00:00:00';
        $end = gmdate('Y-m-t 23:59:59', strtotime($month . '-01 00:00:00'));

        $rows = Repository::findInWindow($start, $end);
        $earliest = Repository::earliestRanAt();
        $onCarePlan = Repository::latestCarePlanFlag();

        Layout::pageHeader(
            'Activity',
            'A log of the maintenance work your hosting provider has performed on this site.'
        );

        self::renderCarePlanBanner($onCarePlan);
        self::renderMonthPicker($month, $earliest);
        self::renderTotals($rows);
        self::renderTable($rows);
    }

    private static function renderCarePlanBanner(bool $onCarePlan): void
    {
        if ($onCarePlan) {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #65a30d;">
                <div class="clockwork-card__body">
                    <strong>You're on a care plan.</strong>
                    The maintenance work shown below is included in your monthly plan — there's nothing extra to pay.
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #f59e0b;">
                <div class="clockwork-card__body">
                    <strong>Not on a care plan.</strong>
                    The work shown below is billed as ad-hoc maintenance.
                    Talk to your agency about a monthly care plan if you'd like updates and routine work included.
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
        <div class="clockwork-card">
            <div class="clockwork-card__body" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-right: 6px;">Month</span>
                <?php foreach ($months as $m) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&month=' . $m)); ?>"
                       class="button <?php echo $m === $current ? 'button-primary' : ''; ?>"
                       style="font-size: 12px;">
                        <?php echo esc_html(gmdate('M Y', strtotime($m . '-01'))); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function renderTotals(array $rows): void
    {
        $total = count($rows);
        $byType = [];
        foreach ($rows as $r) {
            $t = (string) ($r['action_type'] ?? '');
            $byType[$t] = ($byType[$t] ?? 0) + 1;
        }

        $labels = self::typeLabels();
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
                <p style="margin: 0 0 8px;">
                    <strong><?php echo (int) $total; ?> action<?php echo $total === 1 ? '' : 's'; ?></strong>
                    performed this month<?php echo $total > 0 ? ':' : '.'; ?>
                </p>
                <?php if ($byType) : ?>
                    <ul style="margin: 0; padding-left: 1.25rem;">
                        <?php foreach ($byType as $type => $count) : ?>
                            <li><?php echo esc_html(($labels[$type] ?? $type) . ': ' . $count); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function renderTable(array $rows): void
    {
        $labels = self::typeLabels();
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
                                        <?php echo esc_html($labels[$type] ?? $type); ?>
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

    /** @return array<string, string> */
    private static function typeLabels(): array
    {
        return [
            'plugin_update' => 'Plugin update',
            'sso_login' => 'SSO login',
            'companion_install' => 'Companion install',
            'companion_update' => 'Companion update',
            'manual_ban' => 'Manual ban',
            'manual_unban' => 'Manual unban',
            'review_approve' => 'Review approve',
            'review_dismiss' => 'Review dismiss',
            'care_plan_toggled' => 'Care plan toggled',
        ];
    }
}
