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
 *      picks up the new value.
 *   2. Default: stored in wp_options under 'clockwork_companion_secret'.
 *      Easier to bootstrap; supports automated rotation.
 *
 * Rotation race: Clockwork's rotate flow is "sign with old, store new in
 * response, persist on Clockwork side". If Clockwork loses the response
 * mid-flight, it ends up with a stale secret and a reinstall is needed.
 * HmacVerifier's 5-minute replay window keeps the desync brief in normal
 * paths.
 */
class Secret
{
    public const OPTION_KEY = 'clockwork_companion_secret';

    public const CONSTANT_NAME = 'CLOCKWORK_COMPANION_SECRET';

    public static function ensure(): string
    {
        $existing = self::get();
        if ($existing !== '') {
            return $existing;
        }

        return self::rotate();
    }

    public static function get(): string
    {
        // Constant takes precedence — keeps the secret out of wp_options.
        if (defined(self::CONSTANT_NAME)) {
            $constant = constant(self::CONSTANT_NAME);
            if (is_string($constant) && $constant !== '') {
                return $constant;
            }
        }

        $value = get_option(self::OPTION_KEY, '');

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
        return ! defined(self::CONSTANT_NAME);
    }

    public static function rotate(): string
    {
        if (! self::isManaged()) {
            throw new \RuntimeException('Secret is pinned via wp-config.php constant — rotate it there manually.');
        }

        $bytes = random_bytes(32);
        $secret = bin2hex($bytes);
        update_option(self::OPTION_KEY, $secret, false);

        return $secret;
    }
}
