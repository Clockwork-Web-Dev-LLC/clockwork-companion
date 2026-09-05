<?php

namespace ClockworkCompanion\TwoFactor;

use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\Admin\Pages\TwoFactorPage;

/**
 * Nags eligible admins into setting up two-factor, with an enforced grace
 * period.
 *
 * Scope is deliberately narrow: only users the Clockwork menu itself is
 * visible to (Menu::currentUserIsAgency() + the menu's own manage_options
 * capability — i.e. Aaron's own accounts, not client admins) ever see this.
 * Client sites get Companion installed with 2FA available but not pushed on
 * them; nagging a client admin to set up a feature their sidebar doesn't
 * even show them would just be confusing (see Menu's docblock on menu
 * visibility).
 *
 * Grace period: stored as an absolute deadline (unix timestamp) in
 * META_GRACE_DEADLINE, not a "days remaining" counter — that's what makes
 * it directly settable per user (setGraceDeadline()) instead of only ever
 * computable from a fixed start point. The first time an eligible,
 * un-enrolled user is observed, the deadline is lazily initialised to
 * now + CLOCKWORK_2FA_GRACE_DAYS days (30 by default; filterable via
 * clockwork_companion_2fa_grace_days). From the Login Security page's Team
 * Status table, any agency admin can override a specific teammate's
 * deadline directly (extend someone on vacation, or cut someone's grace
 * short) via handleSetGrace() — deliberately a *different* actor/target
 * shape than TwoFactorActions (which only ever acts on the current user);
 * this one exists precisely to act on someone else's account, so it carries
 * its own capability + agency check on the ACTING user, and a per-target
 * nonce so one teammate's form can't be replayed against another's account.
 *
 * Enforcement: while a user's deadline is in the future, they get a
 * dismissible-for-24h nag banner with a day countdown. Once it passes, the
 * banner becomes non-dismissible and every wp-admin request from that user
 * is redirect-locked to the Login Security page until they enroll.
 *
 * Deliberately does NOT touch the wp-login.php flow the way LoginInterceptor
 * does — enrollment is an interactive multi-step admin-page flow (QR scan +
 * confirm), so it can only happen once a session already exists. Enforcing
 * pre-auth would mean building a second enrollment UI at the login screen
 * for no real benefit; redirect-locking wp-admin post-login achieves the
 * same outcome without the risk.
 */
class EnrollmentNudge
{
    private const META_GRACE_DEADLINE = '_clockwork_2fa_grace_deadline';

    private const META_DISMISSED_UNTIL = '_clockwork_2fa_nudge_dismissed_until';

    private const DEFAULT_GRACE_DAYS = 30;

    public const DISMISS_ACTION = 'clockwork_2fa_nudge_dismiss';

    public const SET_GRACE_ACTION = 'clockwork_2fa_set_grace';

    public function register(): void
    {
        add_action('admin_notices', [$this, 'maybeShowNudge']);
        add_action('admin_init', [$this, 'maybeEnforceGrace']);
        add_action('admin_post_'.self::DISMISS_ACTION, [$this, 'handleDismiss']);
        add_action('admin_post_'.self::SET_GRACE_ACTION, [$this, 'handleSetGrace']);
    }

    public function maybeShowNudge(): void
    {
        $userId = get_current_user_id();
        if (! self::isEligible($userId)) {
            return;
        }

        $daysLeft = self::daysLeftFor($userId);
        if ($daysLeft > 0 && self::isDismissedForToday($userId)) {
            return;
        }

        $setupUrl = esc_url(admin_url('admin.php?page='.TwoFactorPage::SLUG));

        if ($daysLeft > 0) {
            printf(
                '<div class="notice notice-warning"><p><strong>Set up two-factor authentication</strong> — %d day%s left before it&rsquo;s required on this account. <a href="%s">Set it up now</a>, it only takes a minute. &nbsp;%s</p></div>',
                $daysLeft,
                $daysLeft === 1 ? '' : 's',
                $setupUrl,
                self::dismissLink()
            );

            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>Two-factor authentication is now required</strong> on this account — wp-admin is locked to the <a href="%s">Login Security page</a> until it&rsquo;s set up. It only takes a minute.</p></div>',
            $setupUrl
        );
    }

    /**
     * Redirect-lock every wp-admin request to the Login Security page once
     * the grace period has elapsed. Explicitly lets through: the Login
     * Security page itself, admin-post.php (so its own enroll/confirm,
     * dismiss, and set-grace actions can run) and admin-ajax.php (heartbeat
     * and friends) — anything else redirects.
     */
    public function maybeEnforceGrace(): void
    {
        $userId = get_current_user_id();
        if (! self::isEligible($userId)) {
            return;
        }
        if (self::daysLeftFor($userId) > 0) {
            return;
        }
        if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }

        global $pagenow;
        if ($pagenow === 'admin.php' && ($_GET['page'] ?? '') === TwoFactorPage::SLUG) {
            return;
        }
        if ($pagenow === 'admin-post.php' || $pagenow === 'admin-ajax.php') {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page='.TwoFactorPage::SLUG));
        exit;
    }

    public function handleDismiss(): void
    {
        check_admin_referer(self::DISMISS_ACTION);
        update_user_meta(get_current_user_id(), self::META_DISMISSED_UNTIL, time() + DAY_IN_SECONDS);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /**
     * admin-post.php handler letting an agency admin set (or reset) another
     * eligible teammate's grace deadline from the Team Status table. Unlike
     * TwoFactorActions, this one is explicitly cross-user — gated on the
     * ACTING user's own capability + agency membership, plus a per-target
     * nonce (graceNonceAction()) so a form can't be replayed against a
     * different user_id than the one it was rendered for.
     */
    public function handleSetGrace(): void
    {
        if (! current_user_can(Menu::CAPABILITY) || ! Menu::currentUserIsAgency()) {
            wp_die('You do not have permission to manage two-factor grace periods on this site.', 403);
        }

        $targetId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if ($targetId <= 0 || ! wp_verify_nonce($nonce, self::graceNonceAction($targetId))) {
            wp_die('Invalid request — please return to the Login Security page and try again.', 400);
        }

        $target = get_userdata($targetId);
        if (! $target) {
            wp_die('Unknown user.', 400);
        }

        if (($_POST['grace_op'] ?? '') === 'reset') {
            delete_user_meta($targetId, self::META_GRACE_DEADLINE);
            $flash = sprintf('Reset %s&rsquo;s two-factor grace period back to the default.', $target->display_name);
        } else {
            $days = isset($_POST['days']) ? (int) $_POST['days'] : -1;
            if ($days < 0) {
                wp_die('Invalid number of days.', 400);
            }
            self::setGraceDeadline($targetId, $days);
            $flash = sprintf(
                'Set %s&rsquo;s two-factor grace period to %d day%s from now.',
                $target->display_name,
                $days,
                $days === 1 ? '' : 's'
            );
        }

        wp_safe_redirect(add_query_arg(
            ['page' => TwoFactorPage::SLUG, 'flash' => rawurlencode($flash)],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Whether $userId is in scope for the nudge/enforcement at all: same
     * visibility gate as the Clockwork menu (agency-domain email + the
     * menu's own manage_options capability), and not already enrolled.
     */
    public static function isEligible(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (LoginInterceptor::isDisabled()) {
            return false;
        }
        $user = get_userdata($userId);
        if (! $user) {
            return false;
        }
        if (! Menu::currentUserIsAgency($user) || ! user_can($userId, Menu::CAPABILITY)) {
            return false;
        }
        if (UserSettings::isEnabled($userId)) {
            return false;
        }

        return true;
    }

    /**
     * Days remaining until $userId's grace deadline, rounded up so "12
     * hours left" reads as 1 day, not 0 (0 is reserved for "already
     * expired" — the enforcement branch).
     */
    public static function daysLeftFor(int $userId): int
    {
        $remaining = self::graceDeadline($userId) - time();

        return $remaining > 0 ? (int) ceil($remaining / DAY_IN_SECONDS) : 0;
    }

    /**
     * $userId's grace deadline as a unix timestamp, initialising it to
     * now + the default grace length on first read for that user.
     */
    public static function graceDeadline(int $userId): int
    {
        $deadline = (int) get_user_meta($userId, self::META_GRACE_DEADLINE, true);
        if ($deadline <= 0) {
            $deadline = time() + self::defaultGraceDays() * DAY_IN_SECONDS;
            update_user_meta($userId, self::META_GRACE_DEADLINE, $deadline);
        }

        return $deadline;
    }

    /**
     * Directly set $userId's grace deadline to $days from now. 0 enforces
     * immediately on their next admin request.
     */
    public static function setGraceDeadline(int $userId, int $days): void
    {
        update_user_meta($userId, self::META_GRACE_DEADLINE, time() + max(0, $days) * DAY_IN_SECONDS);
    }

    private static function defaultGraceDays(): int
    {
        if (defined('CLOCKWORK_2FA_GRACE_DAYS')) {
            return max(0, (int) CLOCKWORK_2FA_GRACE_DAYS);
        }

        return max(0, (int) apply_filters('clockwork_companion_2fa_grace_days', self::DEFAULT_GRACE_DAYS));
    }

    private static function isDismissedForToday(int $userId): bool
    {
        $until = (int) get_user_meta($userId, self::META_DISMISSED_UNTIL, true);

        return $until > time();
    }

    private static function dismissLink(): string
    {
        $url = wp_nonce_url(admin_url('admin-post.php?action='.self::DISMISS_ACTION), self::DISMISS_ACTION);

        return sprintf('<a href="%s">Remind me tomorrow</a>', esc_url($url));
    }

    /** Per-target nonce so one user's set-grace form can't be replayed against another's account. */
    public static function graceNonceAction(int $userId): string
    {
        return self::SET_GRACE_ACTION.'_'.$userId;
    }
}
