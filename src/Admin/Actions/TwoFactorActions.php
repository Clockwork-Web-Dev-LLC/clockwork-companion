<?php

namespace ClockworkCompanion\Admin\Actions;

use ClockworkCompanion\Admin\Pages\TwoFactorPage;
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
 * Auth model mirrors RunSecurityScanAction: WP nonce + manage_options.
 * No HMAC — that layer is for Clockwork machine-to-machine calls.
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

    public function register(): void
    {
        add_action('admin_post_'.self::ACTION_HOOK, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to manage login security on this site.', 403);
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die('Invalid request — please return to the Login Security page and try again.', 400);
        }

        $userId = get_current_user_id();
        $op = isset($_POST['op']) ? (string) $_POST['op'] : '';

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
                $this->redirect([
                    'flash' => 'Two-factor authentication is on. Your next sign-in will ask for a code.',
                    'codes' => $this->stashCodes($userId, $codes),
                ]);

            case 'disable':
                UserSettings::disable($userId);
                $this->redirect(['flash' => 'Two-factor authentication is off for your account.']);

            case 'regenerate':
                if (! UserSettings::isEnabled($userId)) {
                    $this->redirect(['flash_error' => 'Two-factor isn\'t enabled — nothing to regenerate.']);
                }
                $codes = UserSettings::generateBackupCodes($userId);
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

            default:
                wp_die('Unknown operation.', 400);
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
        wp_safe_redirect(add_query_arg(
            array_merge(['page' => TwoFactorPage::SLUG], array_map('rawurlencode', $args)),
            admin_url('admin.php')
        ));
        exit;
    }
}
