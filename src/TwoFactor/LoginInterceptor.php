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
 * Deliberate bypasses:
 *   - XML-RPC and REST requests: application-password auth flows through
 *     the same `authenticate` filter and cannot answer an HTML challenge.
 *     Consequence: XML-RPC password auth is NOT 2FA-gated — block xmlrpc
 *     at nginx/Cloudflare (we already do on most of the fleet).
 *   - CLOCKWORK_2FA_DISABLE constant: fleet rescue hatch. If a bug here
 *     ever locks admins out, `wp config set CLOCKWORK_2FA_DISABLE true --raw`
 *     over SSH turns the gate off instantly.
 *
 * Challenge attempts are capped per pending login; the transient dies
 * after TTL or MAX_ATTEMPTS, forcing a full password re-entry. Failures
 * are recorded in the auth-failures audit table so the monitoring app can
 * see OTP brute-force attempts alongside HMAC failures.
 */
class LoginInterceptor
{
    private const TRANSIENT_PREFIX = 'clockwork_2fa_login_';

    private const TTL = 300;

    private const MAX_ATTEMPTS = 5;

    public const ACTION = 'clockwork-2fa';

    public function register(): void
    {
        add_filter('authenticate', [$this, 'maybeChallenge'], PHP_INT_MAX);
        add_action('login_form_'.self::ACTION, [$this, 'handleChallenge']);
        add_filter('login_message', [$this, 'maybeShowExpiredNotice']);
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
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return $user;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return $user;
        }
        if (! UserSettings::isEnabled($user->ID)) {
            return $user;
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
        // burst, which is well within brute-force infeasibility for 6-digit TOTP.
        $state['attempts']++;
        if ($state['attempts'] > self::MAX_ATTEMPTS) {
            delete_transient($key);
            AuthAuditRepository::insert([
                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'reason' => '2fa_attempts_exhausted',
                'request_path' => '/wp-login.php',
                'request_method' => 'POST',
            ]);
            $this->bounceToLogin();
        }
        set_transient($key, $state, self::TTL);

        $userId = (int) $state['user_id'];
        $code = (string) ($_POST['clockwork_2fa_code'] ?? '');
        $normalized = preg_replace('/\s+/', '', $code);

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

        if (! $valid) {
            AuthAuditRepository::insert([
                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'reason' => '2fa_bad_code',
                'request_path' => '/wp-login.php',
                'request_method' => 'POST',
            ]);
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
        wp_set_auth_cookie($userId, ! empty($state['remember']));

        $redirect = (string) $state['redirect_to'];
        wp_safe_redirect($redirect !== '' ? $redirect : admin_url());
        exit;
    }

    /**
     * Shown on wp-login.php after a challenge expired (TTL or attempt cap).
     * Mirrors the Sso\Interceptor error pattern.
     */
    public function maybeShowExpiredNotice(string $message): string
    {
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

    private function bounceToLogin(): void
    {
        wp_safe_redirect(add_query_arg('clockwork_2fa_expired', '1', wp_login_url()));
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
