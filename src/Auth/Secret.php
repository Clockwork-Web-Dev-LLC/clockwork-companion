<?php

namespace ClockworkCompanion\Auth;

/**
 * Per-site shared secret used for HMAC signing of Clockwork → Companion calls.
 *
 * Generated on first plugin load; stored in wp_options under
 * 'clockwork_companion_secret'. Mirrored back to Clockwork's
 * sites.companion_secret at install time (encrypted at rest there).
 *
 * Rotation: call rotate(); the old secret is overwritten in place. Brief
 * race window during deploy where Clockwork may still be using the old
 * value, but HmacVerifier's 5-minute replay window expires it shortly.
 */
class Secret
{
    private const OPTION_KEY = 'clockwork_companion_secret';

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
        $value = get_option(self::OPTION_KEY, '');

        return is_string($value) ? $value : '';
    }

    public static function rotate(): string
    {
        $bytes = random_bytes(32);
        $secret = bin2hex($bytes);
        update_option(self::OPTION_KEY, $secret, false);

        return $secret;
    }
}
