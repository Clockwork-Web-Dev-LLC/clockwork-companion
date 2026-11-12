<?php

namespace ClockworkCompanion\TwoFactor;

use ClockworkCompanion\AuthAudit\Repository as AuthAuditRepository;

/**
 * The gate between "password verified" and "session granted".
 *
 * Hooks `authenticate` at PHP_INT_MAX — after core's password check and
 * after anything else in the chain — so by the time we see a WP_User, the
 * password is already proven. If that user has 2FA enabled we never let
 * the auth cookie get set: we stash the pending login in a transient keyed
 * by an unguessable token, print the challenge page, and exit. The cookie
 * is only set by handleChallenge() after a valid TOTP or backup code.
 *
 * This "never set the cookie" shape (vs. the older set-then-destroy
 * pattern) means there is no window where a half-authenticated session
 * exists.
 *
 * XML-RPC and REST requests cannot answer an HTML challenge. For a user
 * enrolled in 2FA, a plain password is therefore refused there outright —
 * otherwise a password alone would open xmlrpc.php (wp.newPost,
 * wp.uploadFile, ...) for an enrolled administrator. Application passwords
 * keep working: over XML-RPC core checks them in the same `authenticate`
 * chain and fires `application_password_did_authenticate` when one
 * matches, which is what lets them through here; over REST (Basic auth)
 * core checks them from `determine_current_user` and never runs this
 * filter at all.
 *
 * Rescue hatch: the CLOCKWORK_2FA_DISABLE constant. If a bug here ever
 * locks admins out, `wp config set CLOCKWORK_2FA_DISABLE true --raw` over
 * SSH turns the gate off instantly.
 *
 * Brute-force limits, two layers:
 *   - per pending login: MAX_ATTEMPTS codes, then the token dies and the
 *     password must be entered again;
 *   - per user: a fresh token is issued for every correct password, so the
 *     real cap is a failure counter in user meta — MAX_USER_FAILURES bad
 *     codes within FAILURE_WINDOW locks that account's second step for
 *     LOCKOUT_SECONDS. Cleared by a successful code.
 * Every bad code fires `wp_login_failed`, so login limiters count it like a
 * bad password, and is recorded in the auth-failures audit table. A
 * successful challenge fires `wp_login`, as a normal login would.
 */
class LoginInterceptor
{
    private const TRANSIENT_PREFIX = 'clockwork_2fa_login_';

    private const TTL = 300;

    private const MAX_ATTEMPTS = 5;

    /** Bad codes per user within FAILURE_WINDOW before the account's second step locks. */
    private const MAX_USER_FAILURES = 10;

    private const FAILURE_WINDOW = 900;

    private const LOCKOUT_SECONDS = 900;

    public const META_FAILURES = '_clockwork_2fa_failures';

    public const META_LOCKED_UNTIL = '_clockwork_2fa_locked_until';

    public const ACTION = 'clockwork-2fa';

    /** @var array<int, true> users authenticated by an application password in this request */
    private static array $applicationPasswordUsers = [];

    public function register(): void
    {
        add_filter('authenticate', [$this, 'maybeChallenge'], PHP_INT_MAX);
        add_action('application_password_did_authenticate', [self::class, 'rememberApplicationPassword'], 10, 1);
        add_action('login_form_'.self::ACTION, [$this, 'handleChallenge']);
        add_filter('login_message', [$this, 'maybeShowExpiredNotice']);
    }

    /**
     * @param  mixed  $user
     */
    public static function rememberApplicationPassword($user): void
    {
        if ($user instanceof \WP_User) {
            self::$applicationPasswordUsers[(int) $user->ID] = true;
        }
    }

    /**
     * @param  \WP_User|\WP_Error|null  $user
     * @return \WP_User|\WP_Error|null
     */
    public function maybeChallenge($user)
    {
        if (! $user instanceof \WP_User) {
            return $user;
        }
        if (self::isDisabled()) {
            return $user;
        }
        if (! UserSettings::isEnabled($user->ID)) {
            return $user;
        }
        if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST)) {
            if (isset(self::$applicationPasswordUsers[(int) $user->ID])) {
                return $user;
            }

            return new \WP_Error(
                'clockwork_2fa_required',
                'Two-factor authentication is enabled for this account, so its password cannot be used here. Use an application password.'
            );
        }
        if (self::isLockedOut($user->ID)) {
            return new \WP_Error(
                'clockwork_2fa_locked',
                'Too many incorrect verification codes. Sign-in for this account is paused for a few minutes.'
            );
        }

        $token = bin2hex(random_bytes(24));
        set_transient(self::TRANSIENT_PREFIX.$token, [
            'user_id' => $user->ID,
            'remember' => ! empty($_POST['rememberme']),
            'redirect_to' => (string) ($_REQUEST['redirect_to'] ?? ''),
            'attempts' => 0,
        ], self::TTL);

        $this->renderChallenge($token, '');
        exit;
    }

    /**
     * wp-login.php?action=clockwork-2fa — GET re-renders the form (e.g.
     * after a page refresh), POST verifies the submitted code.
     */
    public function handleChallenge(): void
    {
        $token = (string) ($_REQUEST['clockwork_2fa_token'] ?? '');
        if (! preg_match('/^[a-f0-9]{48}$/', $token)) {
            $this->bounceToLogin();
        }

        $key = self::TRANSIENT_PREFIX.$token;
        $state = get_transient($key);
        if (! is_array($state)) {
            $this->bounceToLogin();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->renderChallenge($token, '');
            exit;
        }

        // get_transient → increment → set_transient is not atomic under Redis/Memcached.
        // Acceptable: races here allow at most ~2× the attempt cap under concurrent
        // burst; the per-user counter in verifyCode() is the real limit.
        $state['attempts']++;
        if ($state['attempts'] > self::MAX_ATTEMPTS) {
            delete_transient($key);
            self::audit('2fa_attempts_exhausted');
            $this->bounceToLogin();
        }
        set_transient($key, $state, self::TTL);

        $userId = (int) $state['user_id'];
        $outcome = self::verifyCode($userId, (string) ($_POST['clockwork_2fa_code'] ?? ''));

        if ($outcome === 'locked') {
            delete_transient($key);
            $this->bounceToLogin('locked');
        }

        if ($outcome !== 'ok') {
            $remaining = self::MAX_ATTEMPTS - $state['attempts'];
            if ($remaining <= 0) {
                delete_transient($key);
                $this->bounceToLogin();
            }
            $this->renderChallenge($token, sprintf(
                'That code didn&rsquo;t work. %d attempt%s left.',
                $remaining,
                $remaining === 1 ? '' : 's'
            ));
            exit;
        }

        delete_transient($key);
        self::completeLogin($userId, ! empty($state['remember']));

        $redirect = (string) $state['redirect_to'];
        wp_safe_redirect($redirect !== '' ? $redirect : admin_url());
        exit;
    }

    /**
     * Check a submitted TOTP or backup code for $userId and do the
     * bookkeeping: per-user failure count and lockout, `wp_login_failed`,
     * audit row. Returns 'ok', 'bad', or 'locked' (the account's second step
     * is locked — nothing was checked, or this failure engaged the lock).
     */
    public static function verifyCode(int $userId, string $code): string
    {
        if (self::isLockedOut($userId) || self::ipLockedOut()) {
            return 'locked';
        }

        $normalized = (string) preg_replace('/\s+/', '', $code);

        if (preg_match('/^\d{6}$/', $normalized)) {
            $stepResult = Totp::verify(
                UserSettings::secret($userId),
                $normalized,
                null,
                UserSettings::lastAcceptedStep($userId)
            );
            $valid = $stepResult !== false;
            if ($valid) {
                UserSettings::recordStep($userId, $stepResult);
            }
        } else {
            $valid = UserSettings::useBackupCode($userId, $normalized);
        }

        if ($valid) {
            delete_user_meta($userId, self::META_FAILURES);
            delete_user_meta($userId, self::META_LOCKED_UNTIL);

            return 'ok';
        }

        return self::recordFailure($userId) ? 'locked' : 'bad';
    }

    /**
     * Grant the session after a verified code, and announce it the way a
     * normal login does so audit and login-limit plugins see 2FA logins.
     */
    public static function completeLogin(int $userId, bool $remember): void
    {
        wp_set_auth_cookie($userId, $remember);

        $user = get_userdata($userId);
        if ($user) {
            if (function_exists('wp_set_current_user')) {
                wp_set_current_user($userId);
            }
            do_action('wp_login', $user->user_login, $user);
        }
    }

    public static function isLockedOut(int $userId): bool
    {
        return (int) get_user_meta($userId, self::META_LOCKED_UNTIL, true) > time();
    }

    /**
     * Count a bad code. Returns true when this failure engaged the lockout.
     */
    private static function recordFailure(int $userId): bool
    {
        $now = time();
        $failures = get_user_meta($userId, self::META_FAILURES, true);
        if (! is_array($failures) || (int) ($failures['first'] ?? 0) < $now - self::FAILURE_WINDOW) {
            $failures = ['count' => 0, 'first' => $now];
        }
        $failures['count'] = (int) $failures['count'] + 1;
        update_user_meta($userId, self::META_FAILURES, $failures);

        self::audit('2fa_bad_code');

        $user = get_userdata($userId);
        // Core's signature: the attempted username and the error. With
        // Gatekeeper enabled, its wp_login_failed handler counts this
        // against the client IP through Store::incrementFailure() — calling
        // the Store here as well would count every bad code twice.
        do_action(
            'wp_login_failed',
            $user ? (string) $user->user_login : '',
            new \WP_Error('clockwork_2fa_bad_code', 'Incorrect two-factor verification code.')
        );

        $max = max(1, (int) apply_filters('clockwork_companion_2fa_max_failures', self::MAX_USER_FAILURES));
        if ($failures['count'] < $max) {
            return false;
        }

        update_user_meta($userId, self::META_LOCKED_UNTIL, $now + self::LOCKOUT_SECONDS);
        delete_user_meta($userId, self::META_FAILURES);
        self::audit('2fa_user_locked');

        return true;
    }

    /**
     * Gatekeeper's lockout stops new password attempts from a locked IP at
     * the start of the authenticate chain, but someone already holding a
     * challenge token could otherwise keep guessing codes from that IP.
     */
    private static function ipLockedOut(): bool
    {
        if (! \ClockworkCompanion\Gatekeeper\Settings::enabled()) {
            return false;
        }

        // Same exemption rule as Gate: a private address is only exempt when
        // it is the TCP peer, not when a proxy relays it as the client's.
        $client = \ClockworkCompanion\Gatekeeper\ClientIp::resolve();
        $fromPeer = $client['source'] === \ClockworkCompanion\Gatekeeper\ClientIp::SOURCE_PEER;
        if (\ClockworkCompanion\Gatekeeper\Store::isIgnored($client['ip'], $fromPeer)) {
            return false;
        }

        return \ClockworkCompanion\Gatekeeper\Store::isLocked($client['ip']) !== null;
    }

    private static function audit(string $reason): void
    {
        // Best-effort: a logging problem must never turn into a login problem.
        try {
            AuthAuditRepository::insert([
                'ip' => \ClockworkCompanion\Auth\HmacVerifier::clientIp(),
                'reason' => $reason,
                'request_path' => '/wp-login.php',
                'request_method' => 'POST',
            ]);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Shown on wp-login.php after a challenge expired (TTL or attempt cap).
     * Mirrors the Sso\Interceptor error pattern.
     */
    public function maybeShowExpiredNotice(?string $message): string
    {
        // Nullable: an earlier login_message callback can return null — a
        // strict string here fatals the login screen (same class of bug as
        // WhiteLabel::filterAdminFooterText on a client site, 2026-09-15).
        $message ??= '';

        if (! empty($_GET['clockwork_2fa_locked'])) {
            return '<div id="login_error">Too many incorrect verification codes. Please wait a few minutes and sign in again.</div>'.$message;
        }

        if (empty($_GET['clockwork_2fa_expired'])) {
            return $message;
        }

        return '<div id="login_error">Your verification window expired. Please sign in again.</div>'.$message;
    }

    public static function isDisabled(): bool
    {
        if (defined('CLOCKWORK_2FA_DISABLE') && CLOCKWORK_2FA_DISABLE) {
            return true;
        }

        return (bool) apply_filters('clockwork_companion_2fa_disabled', false);
    }

    private function bounceToLogin(string $reason = 'expired'): void
    {
        wp_safe_redirect(add_query_arg('clockwork_2fa_'.$reason, '1', wp_login_url()));
        exit;
    }

    /**
     * Minimal standalone challenge page. Rendered directly (no wp-login
     * header/footer helpers — they aren't callable from inside the
     * authenticate filter) with inline styles that echo wp-login's look.
     */
    private function renderChallenge(string $token, string $error): void
    {
        $action = esc_url(add_query_arg('action', self::ACTION, wp_login_url()));

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Verification required &lsaquo; <?php echo esc_html(get_bloginfo('name')); ?></title>
    <style>
        body { background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .box { background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 3px rgba(0,0,0,.04); padding: 32px; width: 320px; }
        h1 { font-size: 18px; margin: 0 0 8px; color: #1d2327; }
        p { font-size: 13px; color: #50575e; margin: 0 0 20px; }
        .error { background: #fcf0f1; border-left: 4px solid #d63638; padding: 8px 12px; font-size: 13px; color: #1d2327; margin-bottom: 16px; }
        input[type=text] { width: 100%; box-sizing: border-box; font-size: 22px; letter-spacing: 4px; text-align: center; padding: 8px; border: 1px solid #8c8f94; border-radius: 3px; }
        button { width: 100%; margin-top: 16px; background: #2271b1; border: none; color: #fff; font-size: 14px; padding: 10px; border-radius: 3px; cursor: pointer; }
        button:hover { background: #135e96; }
        .hint { font-size: 12px; color: #787c82; margin-top: 14px; text-align: center; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Two-factor verification</h1>
        <p>Enter the 6-digit code from your authenticator app.</p>
        <?php if ($error !== '') : ?>
            <div class="error"><?php echo wp_kses($error, []); ?></div>
        <?php endif; ?>
        <form method="post" action="<?php echo $action; ?>">
            <input type="hidden" name="clockwork_2fa_token" value="<?php echo esc_attr($token); ?>">
            <input type="text" name="clockwork_2fa_code" inputmode="numeric" autocomplete="one-time-code" autofocus required>
            <button type="submit">Verify</button>
        </form>
        <div class="hint">Lost your device? Enter a backup code instead.</div>
    </div>
</body>
</html>
        <?php
    }
}
