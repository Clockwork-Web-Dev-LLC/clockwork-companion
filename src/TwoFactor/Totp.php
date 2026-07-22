<?php

namespace ClockworkCompanion\TwoFactor;

/**
 * Minimal RFC 6238 TOTP implementation (SHA-1, 6 digits, 30s period) —
 * the profile every mainstream authenticator app (Google Authenticator,
 * Authy, 1Password, iOS) defaults to.
 *
 * Deliberately zero-dependency and WordPress-free: no composer packages,
 * no WP functions. That keeps it testable from the CLI against the RFC
 * test vectors and safe to load in any context.
 *
 * SHA-1 note: RFC 6238 HMAC-SHA1 is not affected by SHA-1 collision
 * attacks (HMAC security rests on PRF properties, not collision
 * resistance), and SHA-1 is what authenticator apps expect. Using
 * SHA-256 here would silently break codes for most apps.
 */
class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /**
     * Steps of clock drift tolerated on either side of "now". One step
     * (±30s) is the conventional trade-off: enough for phone clock skew,
     * small enough to keep the brute-force surface at 3 valid codes.
     */
    public const WINDOW = 1;

    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a new random secret, returned base32-encoded (the format
     * authenticator apps consume). 20 bytes = 160 bits, the RFC 4226
     * recommended minimum for SHA-1.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Verify a user-supplied code against the secret, tolerating
     * WINDOW steps of clock drift. Constant-time comparison per step.
     */
    public static function verify(string $base32Secret, string $code, ?int $now = null): bool
    {
        $binary = self::base32Decode($base32Secret);
        if ($binary === false || $binary === '') {
            return false;
        }

        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }

        $now = $now ?? time();
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $expected = self::code($binary, $now + ($offset * self::PERIOD));
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute the TOTP code for a binary secret at a given unix time.
     * RFC 4226 dynamic truncation over HMAC-SHA1 of the 64-bit
     * big-endian step counter.
     */
    public static function code(string $binarySecret, int $timestamp): string
    {
        $counter = pack('J', intdiv($timestamp, self::PERIOD));
        $hash = hash_hmac('sha1', $counter, $binarySecret, true);

        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3])
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Build the otpauth:// URI an authenticator app enrolls from (via QR
     * code or manual entry). Label convention: "Issuer:account".
     */
    public static function provisioningUri(string $base32Secret, string $accountName, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $base32Secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    /**
     * RFC 4648 base32, no padding (authenticator apps don't want the
     * trailing "=" and some choke on it).
     */
    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $out = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($binary) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::B32_ALPHABET[($buffer >> $bits) & 0x1F];
            }
        }

        if ($bits > 0) {
            $out .= self::B32_ALPHABET[($buffer << (5 - $bits)) & 0x1F];
        }

        return $out;
    }

    /**
     * @return string|false false on any character outside the alphabet
     */
    public static function base32Decode(string $base32)
    {
        $base32 = strtoupper(str_replace(['=', ' '], '', $base32));
        if ($base32 === '') {
            return '';
        }

        $out = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($base32) as $char) {
            $index = strpos(self::B32_ALPHABET, $char);
            if ($index === false) {
                return false;
            }
            $buffer = ($buffer << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $out;
    }
}
