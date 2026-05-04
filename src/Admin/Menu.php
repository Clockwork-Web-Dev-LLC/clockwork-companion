<?php

namespace ClockworkCompanion\Admin;

use ClockworkCompanion\Admin\Pages\ActivityPage;
use ClockworkCompanion\Admin\Pages\BackupsPage;
use ClockworkCompanion\Admin\Pages\PerformancePage;
use ClockworkCompanion\Admin\Pages\SecurityPage;
use ClockworkCompanion\Admin\Pages\UptimePage;

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
 *
 * Menu visibility is gated on the logged-in user's email domain — only Aaron's
 * own accounts (any user whose email ends in one of the agency domains) see
 * the menu in the sidebar. Client admin users see nothing in the sidebar even
 * though Companion is installed. The pages themselves stay registered with WP
 * (admin.php?page=clockwork still works) so Aaron can navigate via direct URL
 * if he ever needs to. The REST endpoints + SSO interceptor are unaffected —
 * they don't depend on the menu.
 */
class Menu
{
    public const SLUG = 'clockwork';
    public const CAPABILITY = 'manage_options';

    /**
     * Email-address domains whose users see the Clockwork sidebar menu.
     * Match is case-insensitive on the suffix.
     *
     * @var array<int, string>
     */
    public const AGENCY_EMAIL_DOMAINS = [
        '@clockworkwp.com',
        '@clockworkwd.com',
    ];

    public function register(): void
    {
        // addMenu registers all pages (parent + children). maybeHideMenu runs
        // after at priority 999 and removes the visible menu item if the
        // current user isn't agency-domain. The pages stay reachable by URL
        // either way — only the sidebar visibility changes.
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_menu', [$this, 'maybeHideMenu'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Strip the Clockwork menu (and its children) from the sidebar when the
     * current user isn't on an agency-domain email. The pages stay registered
     * — admin.php?page=clockwork still loads.
     */
    public function maybeHideMenu(): void
    {
        if ($this->currentUserIsAgency()) {
            return;
        }

        // remove_menu_page hides the parent. WP's admin-menu rendering doesn't
        // surface orphaned submenus, so the children disappear with the parent.
        remove_menu_page(self::SLUG);
    }

    private function currentUserIsAgency(): bool
    {
        if (! function_exists('wp_get_current_user')) {
            return false;
        }
        $user = wp_get_current_user();
        if (! $user || empty($user->user_email)) {
            return false;
        }
        $email = strtolower((string) $user->user_email);
        foreach (self::AGENCY_EMAIL_DOMAINS as $domain) {
            if (str_ends_with($email, strtolower($domain))) {
                return true;
            }
        }

        return false;
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
            [ActivityPage::class, 'render'],
            $menuIcon,
            80
        );

        // The default sub-page WP creates from add_menu_page is named "Clockwork"
        // (dupes the parent). Replace it with a labelled "Activity" entry so the
        // sub-menu reads as a proper navigation tree. Activity sits first because
        // it's the most-used surface for clients (what we did this month) — Backups
        // is reference info they look at less often.
        add_submenu_page(
            self::SLUG,
            'Activity',
            'Activity',
            self::CAPABILITY,
            self::SLUG,
            [ActivityPage::class, 'render']
        );

        // Order matches the in-page tab strip in Admin/Layout.php — Activity
        // → Uptime → Security → Performance → Backups. Uptime sits second
        // because "is my site up?" is the question clients ask first.
        add_submenu_page(
            self::SLUG,
            'Uptime',
            'Uptime',
            self::CAPABILITY,
            UptimePage::SLUG,
            [UptimePage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Security',
            'Security',
            self::CAPABILITY,
            SecurityPage::SLUG,
            [SecurityPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Performance',
            'Performance',
            self::CAPABILITY,
            PerformancePage::SLUG,
            [PerformancePage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Backups',
            'Backups',
            self::CAPABILITY,
            BackupsPage::SLUG,
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
