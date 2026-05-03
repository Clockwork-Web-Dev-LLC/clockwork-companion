<?php

namespace ClockworkCompanion\Admin;

use ClockworkCompanion\Admin\Pages\BackupsPage;

/**
 * Registers the top-level "Clockwork" admin menu and its sub-pages.
 *
 * Sub-pages live under Admin/Pages/. Each one returns its slug, label, and a
 * render callback. Menu hooks them all in one shot.
 *
 * Capability: 'manage_options' (administrators only). Editor-level visibility
 * is a future option — the current pages contain hosting/backup config that
 * isn't useful to an editor.
 *
 * Position 80 puts us between "Settings" (80) and "Tools" (75) in WP's menu —
 * out of the way of common day-to-day items but visible.
 */
class Menu
{
    public const SLUG = 'clockwork';
    public const CAPABILITY = 'manage_options';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        $iconUrl = plugins_url('assets/clockwork-logo.png', CLOCKWORK_COMPANION_DIR . '/clockwork-companion.php');
        // For the menu icon (16x16 in the sidebar), the logo PNG is too wide.
        // Use a data-URI SVG of the lime sparkle from the brand mark instead.
        $menuIcon = $this->menuIconDataUri();

        add_menu_page(
            'Clockwork',
            'Clockwork',
            self::CAPABILITY,
            self::SLUG,
            [BackupsPage::class, 'render'],
            $menuIcon,
            80
        );

        // The default sub-page WP creates from add_menu_page is named "Clockwork"
        // (dupes the parent). Replace it with a labelled "Backups" entry so the
        // sub-menu reads as a proper navigation tree from day one.
        add_submenu_page(
            self::SLUG,
            'Backups',
            'Backups',
            self::CAPABILITY,
            self::SLUG,
            [BackupsPage::class, 'render']
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        // Only load on Clockwork pages — hookSuffix is "toplevel_page_clockwork"
        // for the parent and "clockwork_page_<slug>" for sub-pages.
        if (! str_contains($hookSuffix, self::SLUG)) {
            return;
        }

        wp_enqueue_style(
            'clockwork-companion-admin',
            plugins_url('assets/admin.css', CLOCKWORK_COMPANION_DIR . '/clockwork-companion.php'),
            [],
            CLOCKWORK_COMPANION_VERSION
        );
    }

    /**
     * Sidebar menu icon — the lime-green sparkle from the Clockwork brand mark
     * inlined as an SVG data URI. WP uses currentColor for menu icons; we set
     * fill="currentColor" so it matches the active/inactive menu state.
     */
    private function menuIconDataUri(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">'
            . '<path d="M12 1l1.8 5.6L19 8l-5.6 1.8L12 15l-1.8-5.2L5 8l5.2-1.4z"/>'
            . '<path d="M19 14l.9 2.5L22 17l-2.1.5L19 20l-.9-2.5L16 17l2.1-.5z" opacity=".85"/>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
