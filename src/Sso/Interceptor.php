<?php

namespace ClockworkCompanion\Sso;

use ClockworkCompanion\Auth\RemoteActionAudit;

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
 * The nonce row is deleted on every redemption attempt that finds it
 * (see claimNonce()). Expired-but-not-yet-deleted nonces are a non-issue because
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

        $option = self::claimNonce($nonce);

        if (! is_array($option)) {
            $this->failTo('used_or_unknown');

            return;
        }

        $expiresAt = (int) ($option['expires_at'] ?? 0);
        if ($expiresAt < time()) {
            self::recordRedemption(false, (int) ($option['user_id'] ?? 0), 'expired');
            $this->failTo('expired');

            return;
        }

        $userId = (int) ($option['user_id'] ?? 0);
        $user = $userId > 0 ? get_user_by('id', $userId) : null;
        if (! $user) {
            self::recordRedemption(false, (int) ($option['user_id'] ?? 0), 'user_gone');
            $this->failTo('user_gone');

            return;
        }

        // Re-verify the user is still an admin — the agency may have demoted them
        // between mint and redemption (rare but defensive).
        if (! user_can($user, 'manage_options')) {
            self::recordRedemption(false, (int) ($option['user_id'] ?? 0), 'not_admin');
            $this->failTo('not_admin');

            return;
        }

        $redirectTo = (string) ($option['redirect_to'] ?? '/wp-admin/');

        // Standard WP login flow.
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID, $user->user_login);
        wp_set_auth_cookie($user->ID, false);
        do_action('wp_login', $user->user_login, $user);
        self::recordRedemption(true, (int) $user->ID, '', (string) $user->user_login);

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

    /**
     * Look the nonce up and consume it in one step, without going through
     * get_option(). get_option() records every miss in the `notoptions`
     * cache entry, which is loaded on every request and which delete_option()
     * on a missing row never cleans — so a flood of
     * `/?clockwork_sso=<random>` grew that entry without bound on sites with
     * a persistent object cache.
     *
     * One-time semantics are kept, and tightened: the row is deleted on
     * every attempt that finds it (valid or not), and only the request whose
     * DELETE actually removed the row may use it, so two simultaneous
     * redemptions of one link cannot both log in.
     *
     * @return array<string, mixed>|null the stored payload, or null when unknown, already used or malformed
     */
    public static function claimNonce(string $nonce): ?array
    {
        global $wpdb;

        $name = self::OPTION_PREFIX.$nonce;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberately bypasses the options cache, see above.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
        if ($raw === null) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete($wpdb->options, ['option_name' => $name], ['%s']);
        // The row was written with update_option(), which may have cached it.
        wp_cache_delete($name, 'options');
        delete_transient($name.'_gc');

        if (! $deleted) {
            return null;
        }

        $value = maybe_unserialize($raw);

        return is_array($value) ? $value : null;
    }

    public function maybeShowError(?string $message): string
    {
        // Nullable: an earlier login_message callback can return null — a
        // strict string here fatals the login screen (same class of bug as
        // WhiteLabel::filterAdminFooterText on a client site, 2026-09-15).
        $message ??= '';

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

    /**
     * Site-side record of a login link being used. Only links that really
     * existed are recorded — a random value in the query string is not worth
     * a row (and must not be able to flood the log).
     */
    private static function recordRedemption(bool $ok, int $userId, string $reason, string $login = ''): void
    {
        if ($login === '' && $userId > 0) {
            $user = get_user_by('id', $userId);
            $login = $user ? (string) $user->user_login : '';
        }

        RemoteActionAudit::record(
            'sso_link_redeemed',
            $login !== '' ? $login : (string) $userId,
            $ok
                ? sprintf('Signed in as %s with a one-time login link.', $login)
                : sprintf('One-time login link refused (%s).', $reason),
            ['user_id' => $userId, 'reason' => $reason !== '' ? $reason : null],
            $ok,
            $ok ? null : $reason
        );
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
