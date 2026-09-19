<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\WhiteLabel\WhiteLabel;

/**
 * Update coverage admin page.
 *
 * Reads cached update exceptions from wp_options['clockwork_update_exceptions']
 * (pushed by Clockwork Control) and displays which plugins or themes currently have
 * automatic updates paused after repeated nightly failures.
 *
 * Manual updates via WordPress remain available at all times.
 */
class UpdateCoveragePage
{
    public const SLUG = 'clockwork-update-coverage';

    public const OPTION = 'clockwork_update_exceptions';

    public static function render(): void
    {
        Layout::render('update-coverage', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $pluginName = WhiteLabel::getPluginName();
        $title = 'Update coverage';
        $subhead = 'Review automatic update coverage for plugins and themes on this site.';

        Layout::pageHeader($title, $subhead);

        $data = get_option(self::OPTION, null);
        $items = is_array($data) && isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

        if (empty($items)) {
            self::renderEmptyState();
        } else {
            self::renderItemsTable($items, $pluginName, is_array($data) ? ($data['generated_at'] ?? null) : null);
        }
    }

    public static function maybeRenderPluginsNotice(): void
    {
        if (! current_user_can(Menu::CAPABILITY)) {
            return;
        }

        global $pagenow;
        if ($pagenow !== 'plugins.php') {
            return;
        }

        $data = get_option(self::OPTION, null);
        if (! is_array($data) || empty($data['items'])) {
            return;
        }

        $count = count($data['items']);
        $url = admin_url('admin.php?page=' . self::SLUG);
        $pluginName = WhiteLabel::getPluginName();

        echo '<div class="notice notice-warning is-dismissible clockwork-update-exceptions-notice">';
        echo '<p>';
        printf(
            /* translators: 1: plugin name, 2: count of plugins, 3: url to view details */
            esc_html__('%1$s has paused automatic updates for %2$d plugin(s) after repeated failures. ', 'clockwork-companion'),
            esc_html($pluginName),
            (int) $count
        );
        printf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('View details', 'clockwork-companion')
        );
        echo '</p>';
        echo '</div>';
    }

    private static function renderEmptyState(): void
    {
        ?>
        <div class="clockwork-card" style="max-width: 720px;">
            <div class="clockwork-card__body" style="padding: 32px 24px; text-align: center;">
                <div style="width: 48px; height: 48px; margin: 0 auto 16px; border-radius: 50%; background: #ecfdf5; display: flex; align-items: center; justify-content: center; color: #059669; font-size: 24px;">
                    ✓
                </div>
                <h3 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 8px;">
                    All updates operational
                </h3>
                <p style="font-size: 14px; color: #4b5563; margin: 0 0 4px; line-height: 1.5;">
                    Automatic plugin updates are on for this site when a care plan is active. Nothing is currently paused.
                </p>
                <p style="font-size: 12px; color: #9ca3af; margin: 0;">
                    Plugins will continue to receive scheduled updates as new versions become available.
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function renderItemsTable(array $items, string $pluginName, ?string $generatedAt): void
    {
        $count = count($items);
        ?>
        <div class="clockwork-card" style="margin-bottom: 20px;">
            <div class="clockwork-card__head" style="display: flex; justify-content: space-between; align-items: center;">
                <h2>Paused automatic updates</h2>
                <span class="clockwork-pill clockwork-pill--warning">
                    <?php echo (int) $count; ?> paused
                </span>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <table class="clockwork-table">
                    <thead>
                        <tr>
                            <th>Plugin / Target</th>
                            <th>Version</th>
                            <th>Paused On</th>
                            <th>Status & Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item) : ?>
                            <?php
                            $name = ! empty($item['name']) ? $item['name'] : ($item['slug'] ?? 'Unknown');
                            $slug = $item['slug'] ?? '';
                            $from = $item['from_version'] ?? '?';
                            $target = $item['attempted_version'] ?? '?';
                            $stoppedAt = ! empty($item['stopped_at']) ? $item['stopped_at'] : '—';
                            $reason = ! empty($item['reason_public'])
                                ? $item['reason_public']
                                : sprintf('Automatic updates did not complete after %d attempts.', (int) ($item['failure_count'] ?? 5));
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($name); ?></strong>
                                    <?php if ($slug !== '') : ?>
                                        <div style="font-size: 11px; color: #6b7280; font-family: monospace;"><?php echo esc_html($slug); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-family: monospace; font-size: 12px;">
                                        <?php echo esc_html($from); ?> &rarr; <?php echo esc_html($target); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size: 12px; color: #4b5563;">
                                        <?php echo esc_html($stoppedAt); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 13px; color: #374151; margin-bottom: 4px;">
                                        <?php echo esc_html($reason); ?>
                                    </div>
                                    <span class="clockwork-pill clockwork-pill--warning" style="font-size: 11px;">
                                        Automatic updates paused
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; color: #475569; line-height: 1.5; max-width: 800px; margin-bottom: 16px;">
            <strong>Manual updates remain available:</strong> You can still update these plugins at any time using WordPress's standard update controls on the Plugins page. <?php echo esc_html($pluginName); ?> has paused automatic retries to avoid repeatedly applying changes that did not complete cleanly.
        </div>

        <?php if ($generatedAt) : ?>
            <p class="clockwork-meta-line" style="font-size: 12px; color: #9ca3af; margin: 8px 4px;">
                Report updated: <?php echo esc_html(self::formatDate($generatedAt)); ?>
            </p>
        <?php endif; ?>
        <?php
    }
    private static function formatDate(?string $value): string
    {
        if (! $value) {
            return '';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return $value;
        }
        return function_exists('wp_date') ? wp_date('M j, Y g:i a', $ts) : date('M j, Y g:i a', $ts);
    }
}
