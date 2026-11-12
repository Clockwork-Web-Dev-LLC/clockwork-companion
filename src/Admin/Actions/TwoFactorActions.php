<?php

namespace ClockworkCompanion\Admin\Actions;

use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\ActionLog\Repository as ActionLogRepository;
use ClockworkCompanion\Admin\Pages\TwoFactorPage;
use ClockworkCompanion\TwoFactor\LoginInterceptor;
use ClockworkCompanion\TwoFactor\UserSettings;
use ClockworkCompanion\TwoFactor\WflsMigrator;

/**
 * admin-post.php handler for the Login Security page.
 *
 * One hook, one `op` discriminator: begin / confirm / cancel / disable /
 * regenerate / migrate. Every op acts on the CURRENT USER ONLY — there is
 * deliberately no way to enroll, disable, or migrate someone else's
 * account from here.
 *
 * Auth model: WP nonce + "is this a logged-in user at all". Every op above
 * mutates only the caller's OWN meta, so manage_options would be the wrong
 * gate — an editor whose 2FA an admin required (TwoFactorAdminActions) has
 * to be able to finish enrolling, and an editor who migrated from Wordfence
 * has to be able to regenerate their own backup codes. remove_wfls is the
 * one exception and keeps the full admin gate: it deletes a plugin for the
 * entire site. No HMAC anywhere here — that layer is for Clockwork
 * machine-to-machine calls. Cross-user operations live in
 * TwoFactorAdminActions, which carries its own, much stricter, checks.
 *
 * Changing an active second factor — disable, regenerate backup codes, or
 * confirming a new secret over an active one — also needs a current code
 * from the authenticator app (or a backup code) in `current_code`. A nonce
 * and a session alone would let a hijacked session silently remove or swap
 * the factor that is meant to survive a stolen password. Bad codes count
 * towards the same per-user lockout as the login challenge. Every such
 * change, and every refused attempt, is written to the action log.
 *
 * Backup-code handoff: ops that mint codes (confirm, migrate, regenerate)
 * can't put them in the redirect URL — query strings land in access logs
 * and browser history. They go into a 60s single-read transient; only the
 * random token rides the redirect, and TwoFactorPage redeems it.
 */
class TwoFactorActions
{
    public const ACTION_HOOK = 'clockwork_two_factor';

    public const NONCE_ACTION = 'clockwork_two_factor';

    /** Ops that change an ACTIVE second factor and so need a current code. */
    public const OPS_NEEDING_CURRENT_CODE = ['disable', 'regenerate', 'confirm'];

    public function register(): void
    {
        add_action('admin_post_'.self::ACTION_HOOK, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (! is_user_logged_in()) {
            wp_die('You do not have permission to manage login security on this site.', 403);
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die('Invalid request — please return to the Login Security page and try again.', 400);
        }

        $userId = get_current_user_id();
        $op = isset($_POST['op']) ? (string) $_POST['op'] : '';
        $wasEnabled = UserSettings::isEnabled($userId);

        if (in_array($op, self::OPS_NEEDING_CURRENT_CODE, true)) {
            $refusal = self::refusalForActiveFactorChange($userId, $op, (string) ($_POST['current_code'] ?? ''));
            if ($refusal !== null) {
                $this->redirect(['flash_error' => $refusal]);
            }
        }

        switch ($op) {
            case 'begin':
                UserSettings::beginEnrollment($userId);
                $this->redirect();

            case 'cancel':
                delete_user_meta($userId, UserSettings::META_PENDING_SECRET);
                $this->redirect(['flash' => 'Setup cancelled — nothing was changed.']);

            case 'confirm':
                $codes = UserSettings::confirmEnrollment($userId, (string) ($_POST['code'] ?? ''));
                if ($codes === false) {
                    $this->redirect(['flash_error' => 'That code didn\'t match — check your authenticator app and try again.']);
                }
                self::log($userId, $wasEnabled ? '2fa_reenrolled' : '2fa_enabled', $wasEnabled
                    ? 'Replaced the two-factor secret with a new authenticator app entry.'
                    : 'Turned on two-factor authentication.');
                $this->redirect([
                    'flash' => 'Two-factor authentication is on. Your next sign-in will ask for a code.',
                    'codes' => $this->stashCodes($userId, $codes),
                ]);

            case 'disable':
                UserSettings::disable($userId);
                self::log($userId, '2fa_self_disabled', 'Turned off two-factor authentication for their own account.');
                $this->redirect(['flash' => 'Two-factor authentication is off for your account.']);

            case 'regenerate':
                if (! UserSettings::isEnabled($userId)) {
                    $this->redirect(['flash_error' => 'Two-factor isn\'t enabled — nothing to regenerate.']);
                }
                $codes = UserSettings::generateBackupCodes($userId);
                self::log($userId, '2fa_backup_codes_regenerated', 'Generated a fresh set of backup codes; the previous set no longer works.');
                $this->redirect([
                    'flash' => 'Fresh backup codes generated — the old set no longer works.',
                    'codes' => $this->stashCodes($userId, $codes),
                ]);

            case 'migrate':
                $codes = WflsMigrator::migrate($userId);
                if ($codes === false) {
                    $this->redirect(['flash_error' => 'Nothing to migrate for your account.']);
                }
                $this->redirect([
                    'flash' => 'Migrated from Wordfence Login Security — the same authenticator app entry now protects your Clockwork sign-ins.',
                    'codes' => $this->stashCodes($userId, $codes),
                ]);

            case 'remove_wfls':
                // The only site-wide op in this class — deleting a plugin,
                // not touching your own meta. It keeps the admin gate the
                // rest of the class no longer needs.
                if (! current_user_can('manage_options') || ! Menu::currentUserIsAgency()) {
                    wp_die('You do not have permission to remove plugins on this site.', 403);
                }
                $result = WflsMigrator::removeWfls();
                $this->redirect($result['ok']
                    ? ['flash' => $result['message']]
                    : ['flash_error' => $result['message']]);

            default:
                wp_die('Unknown operation.', 400);
        }
    }

    /**
     * Null when $op may proceed, otherwise the message to show. Only an
     * active factor is protected: with 2FA off there is nothing to prove.
     */
    public static function refusalForActiveFactorChange(int $userId, string $op, string $currentCode): ?string
    {
        if (! UserSettings::isEnabled($userId)) {
            return null;
        }

        $outcome = LoginInterceptor::verifyCode($userId, $currentCode);
        if ($outcome === 'ok') {
            return null;
        }

        self::log($userId, '2fa_change_refused', sprintf('Refused "%s": the current two-factor code was missing or wrong.', $op), false);

        return $outcome === 'locked'
            ? 'Too many incorrect codes — two-factor changes are paused for a few minutes.'
            : 'Enter a current code from your authenticator app (or a backup code) to make this change.';
    }

    private static function log(int $userId, string $actionType, string $summary, bool $ok = true): void
    {
        $user = get_userdata($userId);
        try {
            ActionLogRepository::insert([
                'action_type' => $actionType,
                'target' => $user ? (string) $user->user_login : (string) $userId,
                'summary' => $summary,
                'ok' => $ok,
                'actor' => 'manual',
            ]);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * @param  array<int, string>  $codes
     * @return string token to ride the redirect
     */
    private function stashCodes(int $userId, array $codes): string
    {
        $token = bin2hex(random_bytes(16));
        set_transient(TwoFactorPage::CODES_TRANSIENT_PREFIX.$token, [
            'user_id' => $userId,
            'codes' => $codes,
        ], 60);

        return $token;
    }

    /**
     * @param  array<string, string>  $args
     */
    private function redirect(array $args = []): void
    {
        wp_safe_redirect(self::redirectUrl($args));
        exit;
    }

    /**
     * Login Security page URL carrying $args. add_query_arg() does not
     * encode values, so a display name containing & or # in a flash message
     * would otherwise cut the message short or turn into extra parameters.
     *
     * @param  array<string, string>  $args
     */
    public static function redirectUrl(array $args = []): string
    {
        return add_query_arg(
            array_merge(['page' => TwoFactorPage::SLUG], array_map('rawurlencode', $args)),
            admin_url('admin.php')
        );
    }
}
