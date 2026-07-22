<?php

namespace ClockworkCompanion\TwoFactor;

/**
 * Per-user 2FA state, stored in user meta.
 *
 * Secrets are stored unencrypted (base32) — a deliberate choice. Encrypting
 * with WP salts means a salt rotation silently bricks 2FA for every user on
 * the site, and an attacker with DB write access can reset passwords anyway,
 * so encryption at rest buys almost nothing here. Backup codes ARE hashed
 * (password_hash) because we never need to display them again after the
 * one-time reveal at generation.
 *
 * Enrollment is two-phase: beginEnrollment() stores a *pending* secret the
 * QR code renders from; confirmEnrollment() promotes it only after the user
 * proves their app produces a valid code. This prevents the classic
 * self-lockout where a user enables 2FA against a secret their app never
 * actually scanned.
 */
class UserSettings
{
    public const META_SECRET = '_clockwork_2fa_secret';

    public const META_ENABLED = '_clockwork_2fa_enabled';

    public const META_BACKUP_CODES = '_clockwork_2fa_backup_codes';

    public const META_PENDING_SECRET = '_clockwork_2fa_pending_secret';

    public const BACKUP_CODE_COUNT = 8;

    public static function isEnabled(int $userId): bool
    {
        return get_user_meta($userId, self::META_ENABLED, true) === '1'
            && self::secret($userId) !== '';
    }

    public static function secret(int $userId): string
    {
        return (string) get_user_meta($userId, self::META_SECRET, true);
    }

    /**
     * Start (or restart) enrollment: generate a pending secret and return
     * it base32-encoded for the QR/manual-entry UI. Re-calling replaces any
     * prior pending secret; an already-active secret is untouched until
     * confirmEnrollment() succeeds.
     */
    public static function beginEnrollment(int $userId): string
    {
        $secret = Totp::generateSecret();
        update_user_meta($userId, self::META_PENDING_SECRET, $secret);

        return $secret;
    }

    public static function pendingSecret(int $userId): string
    {
        return (string) get_user_meta($userId, self::META_PENDING_SECRET, true);
    }

    /**
     * Promote the pending secret to active if the supplied code verifies
     * against it. Returns the freshly generated backup codes (plaintext,
     * for one-time display) on success, false on a bad code.
     *
     * @return array<int, string>|false
     */
    public static function confirmEnrollment(int $userId, string $code)
    {
        $pending = self::pendingSecret($userId);
        if ($pending === '' || ! Totp::verify($pending, $code)) {
            return false;
        }

        update_user_meta($userId, self::META_SECRET, $pending);
        update_user_meta($userId, self::META_ENABLED, '1');
        delete_user_meta($userId, self::META_PENDING_SECRET);

        return self::generateBackupCodes($userId);
    }

    /**
     * Activate 2FA with a caller-supplied secret, bypassing the two-phase
     * enrollment. Only for migration paths (e.g. Wordfence Login Security)
     * where the user's authenticator app already holds this exact secret —
     * a confirmation code adds nothing there.
     *
     * @return array<int, string> fresh backup codes, plaintext, shown once
     */
    public static function activateWithSecret(int $userId, string $base32Secret): array
    {
        update_user_meta($userId, self::META_SECRET, $base32Secret);
        update_user_meta($userId, self::META_ENABLED, '1');
        delete_user_meta($userId, self::META_PENDING_SECRET);

        return self::generateBackupCodes($userId);
    }

    public static function disable(int $userId): void
    {
        delete_user_meta($userId, self::META_ENABLED);
        delete_user_meta($userId, self::META_SECRET);
        delete_user_meta($userId, self::META_BACKUP_CODES);
        delete_user_meta($userId, self::META_PENDING_SECRET);
    }

    /**
     * Generate a fresh set of backup codes, replacing any existing set.
     * Stored hashed; the plaintext return value is the only time they are
     * ever readable. Format XXXX-XXXX (hex) so they can't be mistaken for
     * a 6-digit TOTP code.
     *
     * @return array<int, string>
     */
    public static function generateBackupCodes(int $userId): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $code = substr($code, 0, 4).'-'.substr($code, 4);
            $plain[] = $code;
            $hashed[] = password_hash($code, PASSWORD_DEFAULT);
        }

        update_user_meta($userId, self::META_BACKUP_CODES, $hashed);

        return $plain;
    }

    /**
     * Verify a backup code and burn it on success (each code is single-use).
     */
    public static function useBackupCode(int $userId, string $code): bool
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($code) !== 8) {
            return false;
        }
        $code = substr($code, 0, 4).'-'.substr($code, 4);

        $hashed = get_user_meta($userId, self::META_BACKUP_CODES, true);
        if (! is_array($hashed)) {
            return false;
        }

        foreach ($hashed as $i => $hash) {
            if (password_verify($code, (string) $hash)) {
                unset($hashed[$i]);
                update_user_meta($userId, self::META_BACKUP_CODES, array_values($hashed));

                return true;
            }
        }

        return false;
    }

    public static function backupCodesRemaining(int $userId): int
    {
        $hashed = get_user_meta($userId, self::META_BACKUP_CODES, true);

        return is_array($hashed) ? count($hashed) : 0;
    }
}
