<?php

namespace ClockworkCompanion\Admin\Actions;

use ClockworkCompanion\ActionLog\Repository as ActionLogRepository;
use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\Admin\Pages\TwoFactorPage;
use ClockworkCompanion\TwoFactor\EnrollmentNudge;
use ClockworkCompanion\TwoFactor\UserSettings;

/**
 * admin-post.php handler for the cross-user 2FA controls in the Login
 * Security page's Team Status table.
 *
 * This is the deliberate counterpart to TwoFactorActions, which acts on the
 * CURRENT USER ONLY. Everything here acts on SOMEONE ELSE, so the whole
 * class is built around that asymmetry — same actor/target shape as
 * EnrollmentNudge::handleSetGrace(): a capability + agency check on the
 * ACTING user, and a per-target nonce so a form rendered for one teammate
 * can't be replayed against another's account by editing user_id.
 *
 * Three ops:
 *
 *   require    — mark the target as required to enroll and set their grace
 *                deadline to 0, so their next wp-admin request is
 *                redirect-locked to the Login Security page until they set
 *                2FA up themselves.
 *   unrequire  — drop that requirement and reset the deadline.
 *   disable    — turn the target's 2FA off entirely. The lockout-recovery
 *                path: someone lost their phone and their backup codes.
 *
 * There is deliberately NO "enroll on their behalf" op. An admin cannot scan
 * a QR code for someone else without also learning their secret, and a
 * second factor a second person holds is not a second factor. `require` is
 * what "enable 2FA for that user" means here: the user still enrolls
 * themselves, they just no longer have the option not to.
 *
 * `disable` clears the required flag too. Turning 2FA off means off — if
 * the intent was "reset so they can re-enroll", the admin presses Require
 * afterwards, which is one visible click rather than a silent re-lock the
 * operator didn't ask for. Targets covered by the automatic agency rule are
 * picked back up by EnrollmentNudge on a fresh default grace window either
 * way.
 *
 * Every mutation writes an action-log row. Removing another administrator's
 * second factor is precisely the move an attacker with one compromised
 * admin account would make, so it must not be a silent operation.
 */
class TwoFactorAdminActions
{
    public const ACTION_HOOK = 'clockwork_two_factor_admin';

    private const NONCE_PREFIX = 'clockwork_2fa_admin_';

    public function register(): void
    {
        add_action('admin_post_'.self::ACTION_HOOK, [$this, 'handle']);
    }

    /** Per-target nonce — see the class docblock on replay across user_ids. */
    public static function nonceAction(int $targetId): string
    {
        return self::NONCE_PREFIX.$targetId;
    }

    /**
     * Why the current user may NOT manage $targetId's two-factor setup, or
     * null when they may. Split out from handle() so the page can decide
     * whether to render the controls at all using exactly the rule the
     * handler enforces, and so the rule itself is unit-testable without
     * going through wp_die()/exit.
     */
    public static function denialReason(int $targetId): ?string
    {
        if (! current_user_can(Menu::CAPABILITY) || ! Menu::currentUserIsAgency()) {
            return 'You do not have permission to manage two-factor authentication for other users on this site.';
        }

        if ($targetId <= 0) {
            return 'Unknown user.';
        }

        // Your own account is managed from the card above, which can show
        // you a QR code and your backup codes. Routing self-actions through
        // the cross-user handler would mean two paths to the same state.
        if ($targetId === get_current_user_id()) {
            return 'Use the "Your two-factor authentication" card to change your own settings.';
        }

        $target = get_userdata($targetId);
        if (! $target) {
            return 'Unknown user.';
        }

        // Only rows the Team Status table actually renders are actionable.
        $roles = is_array($target->roles ?? null) ? $target->roles : [];
        if (array_intersect($roles, UserSettings::TEAM_ROLES) === []) {
            return 'That account is not an administrator or editor.';
        }

        return null;
    }

    public function handle(): void
    {
        $targetId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        $denial = self::denialReason($targetId);
        if ($denial !== null) {
            wp_die($denial, 403);
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (! wp_verify_nonce($nonce, self::nonceAction($targetId))) {
            wp_die('Invalid request — please return to the Login Security page and try again.', 400);
        }

        $target = get_userdata($targetId);
        $name = (string) $target->display_name;
        $op = isset($_POST['op']) ? (string) $_POST['op'] : '';

        switch ($op) {
            case 'require':
                if (UserSettings::isEnabled($targetId)) {
                    $this->redirect(['flash_error' => sprintf('%s already has two-factor enabled.', $name)]);
                }
                EnrollmentNudge::setRequired($targetId);
                $this->log($target, '2fa_required', sprintf(
                    'Required two-factor authentication for %s — wp-admin is locked to the Login Security page until they enroll.',
                    $target->user_login
                ));
                $this->redirect(['flash' => sprintf(
                    'Two-factor is now required for %s. Their next wp-admin page load is locked to the Login Security page until they set it up — they scan their own code, you never see it.',
                    $name
                )]);

            case 'unrequire':
                if (! EnrollmentNudge::isExplicitlyRequired($targetId)) {
                    $this->redirect(['flash_error' => sprintf('Two-factor is not explicitly required for %s.', $name)]);
                }
                EnrollmentNudge::clearRequired($targetId);
                $this->log($target, '2fa_unrequired', sprintf(
                    'Dropped the two-factor requirement for %s.',
                    $target->user_login
                ));
                $this->redirect(['flash' => sprintf('%s is no longer required to set up two-factor.', $name)]);

            case 'disable':
                if (! UserSettings::isEnabled($targetId)) {
                    $this->redirect(['flash_error' => sprintf('%s does not have two-factor enabled.', $name)]);
                }
                UserSettings::disable($targetId);
                EnrollmentNudge::clearRequired($targetId);
                $this->log($target, '2fa_disabled_by_admin', sprintf(
                    'Turned OFF two-factor authentication for %s — their sign-ins now need only a password.',
                    $target->user_login
                ));
                $this->redirect(['flash' => sprintf(
                    'Two-factor is off for %s — their secret and backup codes were deleted, so they will need to enroll from scratch.',
                    $name
                )]);

            default:
                wp_die('Unknown operation.', 400);
        }
    }

    /**
     * Action-log row naming both sides. The actor matters as much as the
     * target here: "2FA was turned off for jane" is only half a story
     * without "...by ops-bob".
     */
    private function log(\WP_User $target, string $type, string $summary): void
    {
        $actor = wp_get_current_user();

        ActionLogRepository::insert([
            'action_type' => $type,
            'target' => (string) $target->user_login,
            'summary' => $summary,
            'details' => [
                'target_user_id' => (int) $target->ID,
                'target_login' => (string) $target->user_login,
                'actor_user_id' => (int) ($actor->ID ?? 0),
                'actor_login' => (string) ($actor->user_login ?? ''),
            ],
            'ok' => true,
            'actor' => 'manual',
        ]);
    }

    /**
     * @param  array<string, string>  $args
     */
    private function redirect(array $args = []): void
    {
        wp_safe_redirect(add_query_arg(
            array_merge(['page' => TwoFactorPage::SLUG], array_map('rawurlencode', $args)),
            admin_url('admin.php')
        ));
        exit;
    }
}
