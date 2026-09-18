<?php

namespace ClockworkCompanion\Admin;

use ClockworkCompanion\Admin\Pages\ActivityPage;
use ClockworkCompanion\Admin\Pages\ConnectionPage;
use ClockworkCompanion\Admin\Pages\BackupsPage;
use ClockworkCompanion\Admin\Pages\FormsPage;
use ClockworkCompanion\Admin\Pages\PerformancePage;
use ClockworkCompanion\Admin\Pages\SecurityPage;
use ClockworkCompanion\Admin\Pages\TrafficPage;
use ClockworkCompanion\Admin\Pages\TwoFactorPage;
use ClockworkCompanion\Admin\Pages\NotificationsPage;
use ClockworkCompanion\Admin\Pages\UnlockPage;
use ClockworkCompanion\Admin\Pages\UptimePage;
use ClockworkCompanion\WhiteLabel\WhiteLabel;

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
 * The Clockwork menu is public in the admin sidebar to all administrators
 * (users with the manage_options capability).
 *
 * The pages are registered with WP under the `clockwork` slug. REST endpoints
 * and SSO authentication operate independently and do not depend on the menu.
 */
class Menu
{
    public const SLUG = 'clockwork';
    public const CAPABILITY = 'manage_options';

    public function register(): void
    {
        // addMenu registers all pages (parent + children). The menu is public
        // to all administrators with the manage_options capability.
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'outputIconCss']);
        add_action('admin_post_clockwork_save_notifications', [NotificationsPage::class, 'handleSave']);

        UnlockPage::registerAjax();
    }

    /**
     * Override WordPress's default menu-image rules for our SVG icon.
     *
     * WP's global `.wp-menu-image img` rule applies `opacity: .6` (dimmed
     * inactive state) and `padding: 9px 0 0` (assumes a geometrically-centred
     * 20px icon). Our logo mark has a pointed spike at the top which shifts
     * the visual centre upward, so we add 3px of extra top padding to push it
     * down into an optically centred position. Opacity is forced to 1 at all
     * states — this is a branded icon, not a monochrome dashicon.
     */
    public function outputIconCss(): void
    {
        echo '<style>
#adminmenu #toplevel_page_clockwork .wp-menu-image img {
    opacity: 1 !important;
    padding: 7px 0 0 !important;
}
#adminmenu #toplevel_page_clockwork:hover .wp-menu-image img,
#adminmenu #toplevel_page_clockwork.wp-has-current-submenu .wp-menu-image img,
#adminmenu #toplevel_page_clockwork.current .wp-menu-image img {
    opacity: 1 !important;
}
</style>';
    }

    /**
     * Previously stripped the Clockwork menu from the sidebar for non-agency
     * users. The plugin is now public to all administrators (manage_options),
     * so this method is a no-op kept for backwards compatibility.
     */
    public function maybeHideMenu(): void
    {
        // No-op: Clockwork is public to all administrators.
    }
    /**
     * Whether the Clockwork menu — and everything under it, including the
     * Login Security page — is actually visible to the given user (current
     * user if omitted). Static + public so other features can gate on "is
     * this thing visible to this user at all" without duplicating the
     * agency-domain check (e.g. TwoFactor\EnrollmentNudge — no point nagging
     * a client admin to set up 2FA on a page their sidebar doesn't even
     * show, and no point letting an agency admin tweak a grace period for
     * someone the feature was never gated to in the first place). Takes an
     * explicit $user so callers can check a user other than "whoever is
     * currently logged in" — e.g. a Team Status row for a teammate.
     */
    public static function currentUserIsAgency(?\WP_User $user = null): bool
    {
        $agencyDomains = WhiteLabel::getAgencyEmailDomains();

        // No domains configured means no gating: every user who already
        // cleared the capability check is treated as agency. This is both the
        // out-of-the-box default and the single-agency case, where hiding the
        // menu from your own administrators would be the surprising behaviour.
        if ($agencyDomains === []) {
            return true;
        }

        if ($user === null) {
            if (! function_exists('wp_get_current_user')) {
                return false;
            }
            $user = wp_get_current_user();
        }
        if (! $user || empty($user->user_email)) {
            return false;
        }

        // The configured support address is always treated as an agency
        // domain — an operator who set it up should never lock themselves out.
        $supportEmail = WhiteLabel::getSupportEmail();
        if (! empty($supportEmail) && str_contains($supportEmail, '@')) {
            $domain = '@' . strtolower(substr(strrchr($supportEmail, '@'), 1));
            if (! in_array($domain, $agencyDomains, true)) {
                $agencyDomains[] = $domain;
            }
        }

        $email = strtolower((string) $user->user_email);
        foreach ($agencyDomains as $domain) {
            if (str_ends_with($email, strtolower($domain))) {
                return true;
            }
        }

        return false;
    }

    public function addMenu(): void
    {
        $customIcon = WhiteLabel::getMenuIcon();
        $menuIcon = ! empty($customIcon) ? $customIcon : WhiteLabel::bundledAssetUrl('assets/clockwork-logo-mark.svg').'?v=1.1';
        $menuTitle = WhiteLabel::getMenuTitle();
        $pluginName = WhiteLabel::getPluginName();

        add_menu_page(
            $pluginName,
            $menuTitle,
            self::CAPABILITY,
            self::SLUG,
            [ActivityPage::class, 'render'],
            $menuIcon,
            2
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

        // Login Security sits right after Security — same mental bucket.
        //
        // The one page registered BELOW self::CAPABILITY. It is where a user
        // sets up their own second factor, and EnrollmentNudge redirect-locks
        // wp-admin to it once a grace period expires, so anyone an admin can
        // require 2FA of — editors included — has to be able to open it.
        // The page gates its own site-wide sections on
        // TwoFactorPage::canManageOthers(); a user below manage_options sees
        // only their own enrollment card. Note this does NOT put the item in
        // anyone's sidebar who couldn't already see it: the parent menu is
        // still manage_options, and WP doesn't render orphaned submenus.
        add_submenu_page(
            self::SLUG,
            'Login Security',
            'Login Security',
            TwoFactorPage::SELF_CAPABILITY,
            TwoFactorPage::SLUG,
            [TwoFactorPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Performance',
            'Performance',
            self::CAPABILITY,
            PerformancePage::SLUG,
            [PerformancePage::class, 'render']
        );

        // Traffic sits between Performance and Backups — matches Layout::tabs().
        if (TrafficPage::isSupported()) {
            add_submenu_page(
                self::SLUG,
                'Traffic',
                'Traffic',
                self::CAPABILITY,
                TrafficPage::SLUG,
                [TrafficPage::class, 'render']
            );
        }

        // Forms — contact-form test results pushed by Clockwork's scheduled
        // runs. Lives between Traffic and Backups per Layout::tabs().
        add_submenu_page(
            self::SLUG,
            'Forms',
            'Forms',
            self::CAPABILITY,
            FormsPage::SLUG,
            [FormsPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Backups',
            'Backups',
            self::CAPABILITY,
            BackupsPage::SLUG,
            [BackupsPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Notifications',
            'Notifications',
            self::CAPABILITY,
            NotificationsPage::SLUG,
            [NotificationsPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Connection',
            'Connection',
            self::CAPABILITY,
            ConnectionPage::SLUG,
            [ConnectionPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Unlock',
            'Unlock',
            self::CAPABILITY,
            UnlockPage::SLUG,
            [UnlockPage::class, 'render']
        );

        if (! UnlockPage::isHub()) {
            remove_submenu_page(self::SLUG, UnlockPage::SLUG);
        }
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
            WhiteLabel::bundledAssetUrl('assets/admin.css'),
            [],
            CLOCKWORK_COMPANION_VERSION
        );

        // QR encoder (bundled, MIT) — only the Login Security page renders
        // an enrollment QR, so only it pays the script weight.
        if (str_contains($hookSuffix, TwoFactorPage::SLUG)) {
            wp_enqueue_script(
                'clockwork-companion-qrcode',
                WhiteLabel::bundledAssetUrl('assets/qrcode.js'),
                [],
                CLOCKWORK_COMPANION_VERSION,
                false
            );
        }

    }

}
