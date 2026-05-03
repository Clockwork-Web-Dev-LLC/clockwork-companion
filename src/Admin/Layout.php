<?php

namespace ClockworkCompanion\Admin;

/**
 * Renders the Gravity-Forms-style admin chrome wrapping every Clockwork page.
 *
 * Structure:
 *   <div class="clockwork-admin">
 *     <header class="clockwork-admin__header">  ← dark band, logo, version
 *     <nav class="clockwork-admin__tabs">       ← sub-page nav
 *     <div class="clockwork-admin__body">       ← caller-provided content
 *
 * Tab list is centralised here so adding a new sub-page is one line. Each tab
 * is ['slug' => 'backups', 'label' => 'Backups'] and resolves to ?page=clockwork
 * (with optional &subpage=foo) — for v1.3.0 there's only one (Backups), but
 * the structure is in place for the next round (Updates Pending, Uptime, etc.).
 */
class Layout
{
    /** @return array<int, array{slug: string, label: string, page?: string}> */
    public static function tabs(): array
    {
        return [
            ['slug' => 'backups', 'label' => 'Backups', 'page' => Menu::SLUG],
            ['slug' => 'activity', 'label' => 'Activity', 'page' => \ClockworkCompanion\Admin\Pages\ActivityPage::SLUG],
        ];
    }

    /**
     * Render the chrome and invoke the body callback inside it.
     *
     * @param  callable  $body   Echoes the page-specific HTML.
     */
    public static function render(string $activeSlug, callable $body): void
    {
        $logoUrl = plugins_url(
            'assets/clockwork-logo.png',
            CLOCKWORK_COMPANION_DIR . '/clockwork-companion.php'
        );
        ?>
        <div class="wrap clockwork-admin">
            <header class="clockwork-admin__header">
                <div class="clockwork-admin__brand">
                    <img src="<?php echo esc_url($logoUrl); ?>" alt="Clockwork" />
                    <span class="clockwork-admin__brand-text">Companion</span>
                </div>
                <span class="clockwork-admin__version">v<?php echo esc_html(CLOCKWORK_COMPANION_VERSION); ?></span>
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
}
