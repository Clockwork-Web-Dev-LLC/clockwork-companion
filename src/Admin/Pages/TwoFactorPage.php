<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\TwoFactor\Totp;
use ClockworkCompanion\TwoFactor\UserSettings;
use ClockworkCompanion\TwoFactor\WflsMigrator;

/**
 * Clockwork → Login Security admin page.
 *
 * Two cards:
 *   1. "Your two-factor authentication" — the CURRENT USER's enrollment.
 *      Renders one of four states: WFLS-migratable (banner), enrolled
 *      (status + manage buttons), mid-enrollment (QR + confirm form), or
 *      not enrolled (set-up button).
 *   2. "Team status" — read-only enrollment roll-call for admins/editors,
 *      including who is still on Wordfence Login Security. Exists so the
 *      operator can see at a glance who to nudge before WFLS goes away.
 *
 * Backup codes are displayed exactly once. Action handlers can't put them
 * in the redirect URL (query strings leak into server logs and browser
 * history), so TwoFactorActions stashes them in a 60-second transient
 * keyed by a random token and passes only the token; this page redeems
 * and deletes it on first render.
 *
 * QR rendering happens client-side (bundled qrcode.js, no external
 * requests) from the otpauth:// URI — the secret never travels anywhere
 * except this authenticated page load.
 */
class TwoFactorPage
{
    public const SLUG = 'clockwork-two-factor';

    public const CODES_TRANSIENT_PREFIX = 'clockwork_2fa_codes_';

    public static function render(): void
    {
        Layout::render('two-factor', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        Layout::pageHeader(
            'Login Security',
            'Two-factor authentication for this site\'s sign-ins — codes from your authenticator app, with backup codes for recovery.'
        );

        if (! empty($_GET['flash'])) {
            printf(
                '<div class="notice notice-success" style="margin:0 0 16px;"><p>%s</p></div>',
                esc_html(rawurldecode((string) $_GET['flash']))
            );
        }
        if (! empty($_GET['flash_error'])) {
            printf(
                '<div class="notice notice-error" style="margin:0 0 16px;"><p>%s</p></div>',
                esc_html(rawurldecode((string) $_GET['flash_error']))
            );
        }

        self::renderFreshBackupCodes();
        self::renderSelfCard();
        self::renderTeamCard();
    }

    /**
     * One-time backup code reveal. The token arrives via redirect from
     * TwoFactorActions; the transient dies on first read (or after 60s).
     */
    private static function renderFreshBackupCodes(): void
    {
        $token = isset($_GET['codes']) ? (string) $_GET['codes'] : '';
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return;
        }

        $key = self::CODES_TRANSIENT_PREFIX.$token;
        $stash = get_transient($key);
        delete_transient($key);

        if (! is_array($stash) || (int) ($stash['user_id'] ?? 0) !== get_current_user_id()) {
            return;
        }
        ?>
        <div class="cwk-card" style="border-left: 4px solid #d63638; margin-bottom: 16px;">
            <h2 style="margin-top:0;">Your backup codes — save these now</h2>
            <p>Each code works once, and this is the <strong>only time they will be shown</strong>.
               Store them somewhere safe (password manager, printed copy). If you lose your
               phone, a backup code is how you get back in.</p>
            <div style="display:grid;grid-template-columns:repeat(4,max-content);gap:8px 32px;font-family:monospace;font-size:15px;padding:12px 0;">
                <?php foreach ((array) $stash['codes'] as $code) : ?>
                    <span><?php echo esc_html((string) $code); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private static function renderSelfCard(): void
    {
        $userId = get_current_user_id();
        $user = wp_get_current_user();
        ?>
        <div class="cwk-card" style="margin-bottom: 16px;">
            <h2 style="margin-top:0;">Your two-factor authentication</h2>
            <?php
            if (UserSettings::isEnabled($userId)) {
                self::renderEnrolledState($userId);
            } elseif (WflsMigrator::hasMigratable($userId)) {
                self::renderMigrationBanner($userId);
            } elseif (UserSettings::pendingSecret($userId) !== '') {
                self::renderConfirmState($userId, $user->user_login);
            } else {
                self::renderNotEnrolledState();
            }
            ?>
        </div>
        <?php
    }

    private static function renderEnrolledState(int $userId): void
    {
        $remaining = UserSettings::backupCodesRemaining($userId);
        ?>
        <p>
            <span style="display:inline-block;padding:2px 10px;border-radius:10px;background:#edfaef;color:#00753d;font-weight:600;font-size:12px;">Enabled</span>
            &nbsp;Sign-ins to your account require a code from your authenticator app.
        </p>
        <p style="color:#50575e;">Backup codes remaining: <strong><?php echo (int) $remaining; ?></strong>
            <?php if ($remaining <= 2) : ?>
                <span style="color:#d63638;">— running low, regenerate a fresh set.</span>
            <?php endif; ?>
        </p>
        <div style="display:flex;gap:8px;">
            <?php self::actionForm('regenerate', 'Regenerate backup codes', 'button'); ?>
            <?php self::actionForm('disable', 'Disable two-factor', 'button', 'Disable two-factor authentication for your account? Your next sign-in will only need your password.'); ?>
        </div>
        <?php
    }

    private static function renderMigrationBanner(int $userId): void
    {
        $wflsActive = WflsMigrator::isWflsActive();
        ?>
        <?php if ($wflsActive) : ?>
            <div style="border-left:4px solid #dba617;background:#fcf9e8;padding:12px 16px;margin-bottom:12px;">
                <p style="margin:0 0 6px;"><strong>Wordfence Login Security is protecting this account today</strong> — but Wordfence is discontinuing that plugin.</p>
                <p style="margin:0;">Migrate your setup to Clockwork now: the entry already in your authenticator app
                   keeps working, nothing to re-scan. You'll get a fresh set of backup codes.
                   Wordfence Login Security stays active until every account has migrated —
                   until then both prompts may appear at sign-in.</p>
            </div>
        <?php else : ?>
            <div style="border-left:4px solid #d63638;background:#fcf0f1;padding:12px 16px;margin-bottom:12px;">
                <p style="margin:0 0 6px;"><strong>Wordfence Login Security has been removed, and with it your two-factor prompt.</strong></p>
                <p style="margin:0;">Your setup is still in the database — migrate it now and the entry already in
                   your authenticator app starts protecting your sign-ins again immediately.</p>
            </div>
        <?php endif; ?>
        <?php self::actionForm('migrate', 'Migrate my two-factor setup', 'button button-primary'); ?>
        <p style="color:#787c82;font-size:12px;margin-top:10px;">
            Prefer a clean start? <?php self::actionLink('begin', 'Set up from scratch instead'); ?> — you'll scan a new QR code.
        </p>
        <?php
    }

    private static function renderConfirmState(int $userId, string $login): void
    {
        $secret = UserSettings::pendingSecret($userId);
        $issuer = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'WordPress';
        $uri = Totp::provisioningUri($secret, $login, (string) $issuer);
        ?>
        <p><strong>Step 1.</strong> Scan this QR code with your authenticator app
           (Google Authenticator, Authy, 1Password…).</p>
        <div id="cwk-2fa-qr" style="margin:8px 0;"></div>
        <p style="color:#787c82;font-size:12px;">Can't scan? Enter this key manually:
            <code style="user-select:all;"><?php echo esc_html(trim(chunk_split($secret, 4, ' '))); ?></code>
        </p>
        <p><strong>Step 2.</strong> Enter the 6-digit code your app shows, to prove the pairing worked.
           Two-factor only switches on after this step.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="action" value="<?php echo esc_attr(\ClockworkCompanion\Admin\Actions\TwoFactorActions::ACTION_HOOK); ?>">
            <input type="hidden" name="op" value="confirm">
            <?php wp_nonce_field(\ClockworkCompanion\Admin\Actions\TwoFactorActions::NONCE_ACTION); ?>
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9\s]*"
                   style="width:120px;font-size:18px;letter-spacing:3px;text-align:center;" required autofocus>
            <button type="submit" class="button button-primary">Verify &amp; enable</button>
        </form>
        <p style="color:#787c82;font-size:12px;margin-top:10px;">
            Changed your mind? <?php self::actionLink('cancel', 'Cancel setup'); ?>
        </p>
        <script>
            (function () {
                var qr = qrcode(0, 'M');
                qr.addData(<?php echo wp_json_encode($uri); ?>);
                qr.make();
                document.getElementById('cwk-2fa-qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
            })();
        </script>
        <?php
    }

    private static function renderNotEnrolledState(): void
    {
        ?>
        <p>
            <span style="display:inline-block;padding:2px 10px;border-radius:10px;background:#f0f0f1;color:#50575e;font-weight:600;font-size:12px;">Not set up</span>
            &nbsp;Your account signs in with a password only.
        </p>
        <p style="color:#50575e;">Two-factor authentication adds a 6-digit code from your phone to every
           sign-in, so a stolen password alone can't get in.</p>
        <?php self::actionForm('begin', 'Set up two-factor authentication', 'button button-primary'); ?>
        <?php
    }

    /**
     * Roll-call of accounts that can change content or configuration.
     * Enrollment state per user: Clockwork 2FA / still on WFLS / none.
     */
    private static function renderTeamCard(): void
    {
        $users = get_users([
            'role__in' => ['administrator', 'editor'],
            'orderby' => 'display_name',
            'fields' => 'all',
        ]);
        ?>
        <div class="cwk-card">
            <h2 style="margin-top:0;">Team status</h2>
            <p style="color:#50575e;">Administrator and editor accounts on this site, and where each one's two-factor protection stands.</p>
            <table class="widefat striped" style="max-width:720px;">
                <thead>
                    <tr><th>User</th><th>Role</th><th>Two-factor</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u) : ?>
                    <?php
                    if (UserSettings::isEnabled($u->ID)) {
                        $pill = '<span style="color:#00753d;font-weight:600;">Clockwork 2FA</span>';
                    } elseif (WflsMigrator::hasMigratable($u->ID)) {
                        $pill = WflsMigrator::isWflsActive()
                            ? '<span style="color:#996800;font-weight:600;">Wordfence (needs migration)</span>'
                            : '<span style="color:#d63638;font-weight:600;">Wordfence — gate is OFF, migrate now</span>';
                    } else {
                        $pill = '<span style="color:#787c82;">None</span>';
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($u->display_name); ?> <span style="color:#787c82;">(<?php echo esc_html($u->user_login); ?>)</span></td>
                        <td><?php echo esc_html(implode(', ', $u->roles)); ?></td>
                        <td><?php echo wp_kses_post($pill); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (WflsMigrator::isWflsActive()) : ?>
                <p style="color:#787c82;font-size:12px;margin-top:10px;">
                    Once every account above shows <strong>Clockwork 2FA</strong>, deactivate the
                    Wordfence Login Security plugin — deactivating it earlier would silently remove
                    two-factor for anyone still on it.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function actionForm(string $op, string $label, string $buttonClass, string $confirm = ''): void
    {
        $onSubmit = $confirm !== ''
            ? sprintf(' onsubmit="return confirm(%s);"', esc_attr(wp_json_encode($confirm)))
            : '';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;"<?php echo $onSubmit; ?>>
            <input type="hidden" name="action" value="<?php echo esc_attr(\ClockworkCompanion\Admin\Actions\TwoFactorActions::ACTION_HOOK); ?>">
            <input type="hidden" name="op" value="<?php echo esc_attr($op); ?>">
            <?php wp_nonce_field(\ClockworkCompanion\Admin\Actions\TwoFactorActions::NONCE_ACTION); ?>
            <button type="submit" class="<?php echo esc_attr($buttonClass); ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    /** Text-link variant of actionForm for secondary operations. */
    private static function actionLink(string $op, string $label): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <input type="hidden" name="action" value="<?php echo esc_attr(\ClockworkCompanion\Admin\Actions\TwoFactorActions::ACTION_HOOK); ?>">
            <input type="hidden" name="op" value="<?php echo esc_attr($op); ?>">
            <?php wp_nonce_field(\ClockworkCompanion\Admin\Actions\TwoFactorActions::NONCE_ACTION); ?>
            <button type="submit" class="button-link" style="text-decoration:underline;color:inherit;font-size:inherit;"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }
}
