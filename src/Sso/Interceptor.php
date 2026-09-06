<?php

namespace ClockworkCompanion\Sso;

/**
 * Redeems Clockwork SSO magic-links on the front-end.
 *
 * Hooks into `init` (priority 1) so it runs before themes/plugins can emit
 * output — wp_set_auth_cookie() must precede any header send.
 *
 * URL shape: https://example.com/?clockwork_sso=<48 hex>
 *
 * Behaviour per nonce state:
 *   - valid + unexpired + unredeemed → log user in, delete nonce, redirect to redirect_to
 *   - missing/malformed/expired/already-redeemed → redirect to wp-login.php?clockwork_sso_error=1
 *
 * The nonce option is deleted on every redemption attempt (success or
 * already-used). Expired-but-not-yet-deleted nonces are a non-issue because
 * the expires_at check rejects them; the GC transient cleans up the option
 * within ttl+60s anyway.
 */
class Interceptor
{
    private const OPTION_PREFIX = 'clockwork_sso_';

    private const QUERY_PARAM = 'clockwork_sso';

    public function register(): void
    {
        add_action('init', [$this, 'maybeRedeem'], 1);
        add_filter('login_message', [$this, 'maybeShowError']);
    }

    public function maybeRedeem(): void
    {
        if (! isset($_GET[self::QUERY_PARAM])) {
            return;
        }

        $nonce = (string) $_GET[self::QUERY_PARAM];

        // Shape check: 48 lowercase hex chars (matches bin2hex(random_bytes(24))).
        if (! preg_match('/^[a-f0-9]{48}$/', $nonce)) {
            $this->failTo('invalid');

            return;
        }

        $option = get_option(self::OPTION_PREFIX.$nonce);

        // One-time semantics: delete first, decide second. Even if validation
        // fails below, we don't want the row to survive — every attempted
        // redemption invalidates the nonce.
        delete_option(self::OPTION_PREFIX.$nonce);
        delete_transient(self::OPTION_PREFIX.$nonce.'_gc');

        if (! is_array($option)) {
            $this->failTo('used_or_unknown');

            return;
        }

        $expiresAt = (int) ($option['expires_at'] ?? 0);
        if ($expiresAt < time()) {
            $this->failTo('expired');

            return;
        }

        $userId = (int) ($option['user_id'] ?? 0);
        $user = $userId > 0 ? get_user_by('id', $userId) : null;
        if (! $user) {
            $this->failTo('user_gone');

            return;
        }

        // Re-verify the user is still an admin — the agency may have demoted them
        // between mint and redemption (rare but defensive).
        if (! user_can($user, 'manage_options')) {
            $this->failTo('not_admin');

            return;
        }

        $redirectTo = (string) ($option['redirect_to'] ?? '/wp-admin/');

        // Standard WP login flow.
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID, $user->user_login);
        wp_set_auth_cookie($user->ID, false);
        do_action('wp_login', $user->user_login, $user);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
            error_log(sprintf(
                '[clockwork-sso] redeemed nonce for user_id=%d (login=%s) from ip=%s → %s',
                $user->ID,
                $user->user_login,
                $ip,
                $redirectTo
            ));
        }

        wp_safe_redirect($redirectTo);
        exit;
    }

    public function maybeShowError(string $message): string
    {
        if (! isset($_GET['clockwork_sso_error'])) {
            return $message;
        }

        $reason = isset($_GET['clockwork_sso_reason']) ? (string) $_GET['clockwork_sso_reason'] : 'invalid';
        $human = match ($reason) {
            'expired' => 'That Clockwork SSO link has expired. Generate a fresh one.',
            'used_or_unknown' => 'That Clockwork SSO link has already been used or is unknown.',
            'user_gone' => 'The user that link was minted for no longer exists.',
            'not_admin' => 'The user that link was minted for is no longer an administrator.',
            default => 'That Clockwork SSO link is invalid.',
        };

        return $message.'<p class="message">'.esc_html($human).'</p>';
    }

    private function failTo(string $reason): void
    {
        $url = add_query_arg([
            'clockwork_sso_error' => '1',
            'clockwork_sso_reason' => $reason,
        ], wp_login_url());
        wp_safe_redirect($url);
        exit;
    }
}
