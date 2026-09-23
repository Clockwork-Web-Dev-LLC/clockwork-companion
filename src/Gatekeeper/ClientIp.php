<?php

namespace ClockworkCompanion\Gatekeeper;

class ClientIp
{
    /**
     * Resolves the verified client IP for the current request.
     *
     * Rules:
     * 1. Default: REMOTE_ADDR (on SpinupWP, nginx already rewrites CF-Connecting-IP
     *    via clockwork:refresh-cloudflare-real-ip).
     * 2. Only if CLOCKWORK_COMPANION_TRUST_PROXY is defined and truthy: check
     *    HTTP_CF_CONNECTING_IP, then leftmost entry in HTTP_X_FORWARDED_FOR.
     * 3. Must satisfy FILTER_VALIDATE_IP or returns 'unknown'.
     */
    public static function get(): string
    {
        $candidate = '';

        if (defined('CLOCKWORK_COMPANION_TRUST_PROXY') && constant('CLOCKWORK_COMPANION_TRUST_PROXY')) {
            if (! empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $candidate = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
            } elseif (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $candidate = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            }
        }

        if ($candidate === '' && isset($_SERVER['REMOTE_ADDR'])) {
            $candidate = (string) $_SERVER['REMOTE_ADDR'];
        }

        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }

        return 'unknown';
    }
}
