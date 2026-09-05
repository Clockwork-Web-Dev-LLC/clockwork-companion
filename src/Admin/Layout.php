<?php

namespace ClockworkCompanion\Admin;

use ClockworkCompanion\WhiteLabel\WhiteLabel;

/**
 * Renders the Gravity-Forms-style admin chrome wrapping every Clockwork page.
 *
 * Structure:
 *   <div class="clockwork-admin">
 *     <header class="clockwork-admin__header">  ← dark band, logo, version
 *     <nav class="clockwork-admin__tabs">       ← sub-page nav
 *     <div class="clockwork-admin__body">       ← caller-provided content
 */
class Layout
{
    /** @return array<int, array{slug: string, label: string, page?: string}> */
    public static function tabs(): array
    {
        $all = [
            ['slug' => 'activity', 'label' => 'Activity', 'page' => Menu::SLUG],
            ['slug' => 'uptime', 'label' => 'Uptime', 'page' => \ClockworkCompanion\Admin\Pages\UptimePage::SLUG],
            ['slug' => 'security', 'label' => 'Security', 'page' => \ClockworkCompanion\Admin\Pages\SecurityPage::SLUG],
            ['slug' => 'two-factor', 'label' => '2FA', 'page' => \ClockworkCompanion\Admin\Pages\TwoFactorPage::SLUG],
            ['slug' => 'performance', 'label' => 'Performance', 'page' => \ClockworkCompanion\Admin\Pages\PerformancePage::SLUG],
            ['slug' => 'traffic', 'label' => 'Traffic', 'page' => \ClockworkCompanion\Admin\Pages\TrafficPage::SLUG],
            ['slug' => 'forms', 'label' => 'Forms', 'page' => \ClockworkCompanion\Admin\Pages\FormsPage::SLUG],
            ['slug' => 'backups', 'label' => 'Backups', 'page' => \ClockworkCompanion\Admin\Pages\BackupsPage::SLUG],
            ['slug' => 'notifications', 'label' => 'Notifications', 'page' => \ClockworkCompanion\Admin\Pages\NotificationsPage::SLUG],
        ];

        if (! WhiteLabel::isEnabled()) {
            $all[] = ['slug' => 'branding', 'label' => 'Branding', 'page' => \ClockworkCompanion\Admin\Pages\WhiteLabelPage::SLUG];
        }

        if (defined('CLOCKWORK_UNLOCK_HUB') && CLOCKWORK_UNLOCK_HUB) {
            $all[] = ['slug' => 'unlock', 'label' => 'Unlock', 'page' => \ClockworkCompanion\Admin\Pages\UnlockPage::SLUG];
        }

        return $all;
    }

    /**
     * Render the chrome and invoke the body callback inside it.
     *
     * @param  callable  $body   Echoes the page-specific HTML.
     */
    public static function render(string $activeSlug, callable $body): void
    {
        $logoUrl = WhiteLabel::getLogoUrl();
        $brandText = WhiteLabel::getBrandText();
        $pluginName = WhiteLabel::getPluginName();
        $showVersion = WhiteLabel::isVersionVisible();
        $supportLabel = WhiteLabel::getSupportButtonLabel();
        $supportUrl = WhiteLabel::getSupportUrl();
        ?>
        <div class="wrap clockwork-admin">
            <header class="clockwork-admin__header">
                <div class="clockwork-admin__brand">
                    <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php echo esc_attr($pluginName); ?>" />
                    <span class="clockwork-admin__brand-text"><?php echo esc_html($brandText); ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                    <?php if (! WhiteLabel::areHelpLinksHidden()) : ?>
                        <?php if (!empty($supportUrl)) : ?>
                            <a href="<?php echo esc_url($supportUrl); ?>" target="_blank" rel="noopener noreferrer" class="cwk-header-support-btn" style="text-decoration:none;display:inline-flex;align-items:center;">
                                <?php echo esc_html($supportLabel); ?>
                            </a>
                        <?php else : ?>
                            <button type="button" class="cwk-support-trigger cwk-header-support-btn"><?php echo esc_html($supportLabel); ?></button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($showVersion) : ?>
                        <span class="clockwork-admin__version">v<?php echo esc_html(CLOCKWORK_COMPANION_VERSION); ?></span>
                    <?php endif; ?>
                </div>
            </header>

            <nav class="clockwork-admin__tabs">
                <?php foreach (self::tabs() as $tab) : ?>
                    <?php $url = admin_url('admin.php?page=' . ($tab['page'] ?? Menu::SLUG)); ?>
                    <a class="clockwork-admin__tab <?php echo $tab['slug'] === $activeSlug ? 'is-active' : ''; ?>"
                       href="<?php echo esc_url($url); ?>">
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="clockwork-admin__body">
                <?php $body(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Helper for the "page header" block (h1 + subhead) inside a body callback.
     */
    public static function pageHeader(string $title, string $subhead = ''): void
    {
        ?>
        <div class="clockwork-admin__page-header">
            <h1><?php echo esc_html($title); ?></h1>
            <?php if ($subhead !== '') : ?>
                <p><?php echo esc_html($subhead); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a uniform prev/next pagination strip — used by every admin page
     * that renders a list of rows so the UX is consistent between
     * Activity, Security, Forms, Backups, etc.
     *
     * @param  array<string, scalar>  $baseQuery
     */
    public static function renderPagination(int $totalRows, int $perPage, int $currentPage, array $baseQuery): void
    {
        if ($totalRows <= 0 || $perPage <= 0) {
            return;
        }
        $totalPages = (int) ceil($totalRows / $perPage);
        if ($totalPages <= 1) {
            return;
        }
        $currentPage = max(1, min($currentPage, $totalPages));
        $startRow = (($currentPage - 1) * $perPage) + 1;
        $endRow = min($currentPage * $perPage, $totalRows);

        $linkFor = function (int $page) use ($baseQuery): string {
            $q = $baseQuery;
            $q['paged'] = $page;
            return esc_url(add_query_arg($q, admin_url('admin.php')));
        };
        ?>
        <div class="clockwork-pagination">
            <span class="clockwork-pagination__count">
                <?php printf(
                    /* translators: 1: start row, 2: end row, 3: total row count */
                    esc_html__('%1$s – %2$s of %3$s', 'clockwork-companion'),
                    number_format_i18n($startRow),
                    number_format_i18n($endRow),
                    number_format_i18n($totalRows)
                ); ?>
            </span>
            <span class="clockwork-pagination__nav">
                <?php if ($currentPage > 1) : ?>
                    <a class="button" href="<?php echo $linkFor(1); ?>" aria-label="First page">«</a>
                    <a class="button" href="<?php echo $linkFor($currentPage - 1); ?>" aria-label="Previous page">‹ Prev</a>
                <?php else : ?>
                    <span class="button disabled" aria-disabled="true">«</span>
                    <span class="button disabled" aria-disabled="true">‹ Prev</span>
                <?php endif; ?>

                <span class="clockwork-pagination__page">
                    Page <?php echo (int) $currentPage; ?> of <?php echo (int) $totalPages; ?>
                </span>

                <?php if ($currentPage < $totalPages) : ?>
                    <a class="button" href="<?php echo $linkFor($currentPage + 1); ?>" aria-label="Next page">Next ›</a>
                    <a class="button" href="<?php echo $linkFor($totalPages); ?>" aria-label="Last page">»</a>
                <?php else : ?>
                    <span class="button disabled" aria-disabled="true">Next ›</span>
                    <span class="button disabled" aria-disabled="true">»</span>
                <?php endif; ?>
            </span>
        </div>
        <style>
            .clockwork-pagination {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
                margin-top: 12px;
                font-size: 12px;
                color: #6b7280;
            }
            .clockwork-pagination__nav {
                display: inline-flex;
                gap: 6px;
                align-items: center;
            }
            .clockwork-pagination__nav .button {
                display: inline-flex;
                align-items: center;
                padding: 0 10px;
                font-size: 12px;
                height: 26px;
                min-height: 0;
                line-height: 1;
                box-sizing: border-box;
            }
            .clockwork-pagination__nav .button.disabled {
                opacity: 0.4;
                pointer-events: none;
            }
            .clockwork-pagination__page {
                padding: 0 8px;
                color: #374151;
            }
        </style>
        <?php
    }

    /**
     * Resolve and clamp the `paged` query arg. Centralised so every admin
     * page parses it identically.
     */
    public static function currentPage(): int
    {
        $raw = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;
        return max(1, $raw);
    }
}
