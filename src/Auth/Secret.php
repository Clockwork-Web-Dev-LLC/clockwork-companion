<?php

namespace ClockworkCompanion\Auth;

/**
 * Per-site shared secret used for HMAC signing of Clockwork → Companion calls.
 *
 * Two storage modes (constant takes precedence):
 *   1. Constant `CLOCKWORK_COMPANION_SECRET` defined in wp-config.php.
 *      Recommended for security-sensitive sites — keeps the secret out of
 *      wp_options (and therefore out of DB backups + plugin SQL injection
 *      blast radius). Rotation is manual: edit wp-config.php and rerun
 *      `php artisan clockwork:rotate-companion-secret <site>` so Clockwork
 *      picks up the new value. A constant defined as an empty string pins
 *      nothing: it is ignored and the option below is used.
 *   2. Default: stored in wp_options under 'clockwork_companion_secret'.
 *      Easier to bootstrap; supports automated rotation.
 *
 * Rotation is two-phase. rotate() stores the new secret as *pending* and
 * leaves the current one in force; HmacVerifier accepts a signature made
 * with either, and the first request that verifies with the pending secret
 * promotes it to current (retiring the old one). Clockwork's flow is "sign
 * the rotate call with the old secret, store the new one from the
 * response", so:
 *   - response received → Clockwork signs with the new secret → promoted;
 *   - response lost or garbled → Clockwork keeps signing with the old
 *     secret, which still works; its next rotate simply replaces the
 *     unpromoted pending secret.
 * Previously the new secret replaced the old one immediately, and a lost
 * rotate response left the site unreachable until a reinstall.
 */
class Secret
{
    public const OPTION_KEY = 'clockwork_companion_secret';

    /** New secret issued by rotate(), not yet used by Clockwork. */
    public const PENDING_OPTION_KEY = 'clockwork_companion_secret_pending';

    public const CONSTANT_NAME = 'CLOCKWORK_COMPANION_SECRET';

    public static function ensure(): string
    {
        $existing = self::get();
        if ($existing !== '' || ! self::isManaged()) {
            return $existing;
        }

        // First boot: there is nothing for Clockwork to still be using, so
        // the secret goes straight in as current rather than pending.
        $secret = self::generate();
        if (! update_option(self::OPTION_KEY, $secret, false)) {
            return self::get();
        }

        return $secret;
    }

    public static function get(): string
    {
        // Constant takes precedence — keeps the secret out of wp_options.
        $constant = self::constantValue();
        if ($constant !== '') {
            return $constant;
        }

        $value = get_option(self::OPTION_KEY, '');

        return is_string($value) ? $value : '';
    }

    /**
     * The rotated-but-unconfirmed secret, or '' when there is none (always ''
     * in constant mode).
     */
    public static function pending(): string
    {
        if (! self::isManaged()) {
            return '';
        }

        $value = get_option(self::PENDING_OPTION_KEY, '');

        return is_string($value) ? $value : '';
    }

    /**
     * Constant-mode (`CLOCKWORK_COMPANION_SECRET` defined in wp-config.php)
     * is owned by the site operator, not by us. Refuse to silently ignore the
     * rotation — caller can show this in the UI / Clockwork log so the human
     * knows to rotate the constant manually.
     */
    public static function isManaged(): bool
    {
        return self::constantValue() === '';
    }

    /**
     * Issue a new secret as pending and return it. The current secret keeps
     * working until Clockwork signs a request with the new one. Calling this
     * again before that happens replaces the earlier pending secret.
     *
     * @throws \RuntimeException when the secret is pinned by the constant or
     *                           the new value could not be stored — never
     *                           hand out a secret the site will not accept.
     */
    public static function rotate(): string
    {
        if (! self::isManaged()) {
            throw new \RuntimeException('Secret is pinned via wp-config.php constant — rotate it there manually.');
        }

        $secret = self::generate();
        $stored = update_option(self::PENDING_OPTION_KEY, $secret, false);

        if (! $stored || get_option(self::PENDING_OPTION_KEY, '') !== $secret) {
            throw new \RuntimeException('Could not store the new secret; the current secret is unchanged.');
        }

        return $secret;
    }

    /**
     * Make $pending the current secret. Called by HmacVerifier once a request
     * has verified with it, which proves Clockwork holds it. A no-op when the
     * pending secret has changed since (a newer rotate won the race). The
     * pending row is only removed once the promotion is stored, so a failed
     * write leaves both secrets valid rather than none.
     */
    public static function promote(string $pending): void
    {
        if ($pending === '' || ! self::isManaged() || self::pending() !== $pending) {
            return;
        }

        if (self::get() === $pending || update_option(self::OPTION_KEY, $pending, false)) {
            delete_option(self::PENDING_OPTION_KEY);
        }
    }

    private static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function constantValue(): string
    {
        if (! defined(self::CONSTANT_NAME)) {
            return '';
        }

        $constant = constant(self::CONSTANT_NAME);

        return is_string($constant) ? $constant : '';
    }
}
