<?php

namespace ClockworkCompanion\Gatekeeper;

/**
 * Resolves the client IP for the current request.
 *
 * The TCP peer (REMOTE_ADDR) is the only address nobody can forge. A
 * forwarding header (CF-Connecting-IP, X-Forwarded-For) is honoured only
 * when that peer is a trusted proxy, configured in Gatekeeper's
 * `trusted_proxies` setting, in the CLOCKWORK_COMPANION_TRUSTED_PROXIES
 * constant (comma-separated) or through the
 * `clockwork_companion_trusted_proxies` filter. Entries are IPs, CIDRs, or the
 * keywords "cloudflare" (Cloudflare's published edge ranges, see
 * CLOUDFLARE_RANGES) and "private" (loopback, RFC 1918, IPv6 unique-local).
 *
 * The older CLOCKWORK_COMPANION_TRUST_PROXY constant made every request's
 * headers authoritative, which let any client choose its own address. For
 * Gatekeeper it now means "trust Cloudflare and private peers" when no list
 * is configured.
 *
 * On hosts where the web server already rewrites REMOTE_ADDR from the CDN
 * header (nginx real_ip, Apache mod_remoteip) no proxy needs to be trusted
 * here.
 *
 * Default when no trusted-proxy list is configured at all: a peer inside
 * Cloudflare's published ranges may name the visitor in CF-Connecting-IP
 * (and only there; X-Forwarded-For stays ignored). Without this, a site
 * behind Cloudflare whose web server does not rewrite REMOTE_ADDR sees a
 * shared Cloudflare edge address for every visitor, and one lockout blocks
 * everyone reaching the site through that edge, the owner included. Only
 * Cloudflare's own addresses can be the peer, so a client connecting
 * directly still cannot choose its address with the header. To turn the
 * default off, configure the list as "none" (in the setting, the
 * CLOCKWORK_COMPANION_TRUSTED_PROXIES constant or the filter): no peer is
 * trusted and REMOTE_ADDR is always the client.
 */
class ClientIp
{
    public const SOURCE_PEER = 'peer';

    public const SOURCE_HEADER = 'header';

    /**
     * Cloudflare's published edge ranges (https://www.cloudflare.com/ips/).
     * Cloudflare changes them rarely; the
     * `clockwork_companion_cloudflare_ranges` filter replaces the list.
     */
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function get(): string
    {
        return self::resolve()['ip'];
    }

    /**
     * @return array{ip: string, source: string, peer: string}
     *   ip: the client address ('unknown' only when REMOTE_ADDR itself is invalid);
     *   source: SOURCE_PEER when ip is REMOTE_ADDR, SOURCE_HEADER when a trusted proxy supplied it;
     *   peer: REMOTE_ADDR as received.
     */
    public static function resolve(): array
    {
        $peer = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';

        if ($peer === '' || filter_var($peer, FILTER_VALIDATE_IP) === false) {
            return ['ip' => 'unknown', 'source' => self::SOURCE_PEER, 'peer' => $peer];
        }

        $configured = self::configuredProxies();
        if ($configured === []) {
            // Nothing configured: Cloudflare's edges are trusted for
            // CF-Connecting-IP only. See the class docblock.
            $cf = self::matchesAny($peer, self::cloudflareRanges()) ? self::cloudflareHeader() : null;

            return $cf !== null
                ? ['ip' => $cf, 'source' => self::SOURCE_HEADER, 'peer' => $peer]
                : ['ip' => $peer, 'source' => self::SOURCE_PEER, 'peer' => $peer];
        }

        $trusted = self::expand($configured);
        if ($trusted === [] || ! self::matchesAny($peer, $trusted)) {
            return ['ip' => $peer, 'source' => self::SOURCE_PEER, 'peer' => $peer];
        }

        $cf = self::cloudflareHeader();
        if ($cf !== null) {
            return ['ip' => $cf, 'source' => self::SOURCE_HEADER, 'peer' => $peer];
        }

        // X-Forwarded-For: each proxy appends the address it received the
        // request from, so the list is only trustworthy from the right. Walk
        // it right to left past our own proxies; the first address that is
        // not one of them is the client. Everything left of it was written
        // by the client and is ignored.
        if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hops = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            $client = null;
            foreach ($hops as $hop) {
                if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                    // Garbage in the chain: nothing beyond it can be trusted.
                    break;
                }
                $client = $hop;
                if (! self::matchesAny($hop, $trusted)) {
                    break;
                }
            }
            if ($client !== null) {
                return ['ip' => $client, 'source' => self::SOURCE_HEADER, 'peer' => $peer];
            }
        }

        // A missing or invalid header never maps to a shared placeholder:
        // the peer is still a real, unforgeable address.
        return ['ip' => $peer, 'source' => self::SOURCE_PEER, 'peer' => $peer];
    }

    /**
     * Expanded trusted-proxy list: CIDRs, single IPs, and the "private" keyword.
     * Empty when nothing is configured or the list is "none".
     *
     * @return list<string>
     */
    public static function trustedProxies(): array
    {
        return self::expand(self::configuredProxies());
    }

    /**
     * The trusted-proxy entries as configured (keywords not yet expanded).
     * An empty list means "not configured", which enables the Cloudflare
     * default in resolve(); "none" is an explicit, configured empty list.
     *
     * @return list<string>
     */
    private static function configuredProxies(): array
    {
        $entries = (array) (Settings::get()['trusted_proxies'] ?? []);

        if (defined('CLOCKWORK_COMPANION_TRUSTED_PROXIES')) {
            $entries = array_merge($entries, Settings::sanitizeProxyList(constant('CLOCKWORK_COMPANION_TRUSTED_PROXIES')));
        }

        if ($entries === [] && defined('CLOCKWORK_COMPANION_TRUST_PROXY') && constant('CLOCKWORK_COMPANION_TRUST_PROXY')) {
            $entries = [Settings::PROXY_CLOUDFLARE, Settings::PROXY_PRIVATE];
        }

        if (function_exists('apply_filters')) {
            $entries = (array) apply_filters('clockwork_companion_trusted_proxies', $entries);
        }

        return Settings::sanitizeProxyList($entries);
    }

    /**
     * @param list<string> $entries
     * @return list<string>
     */
    private static function expand(array $entries): array
    {
        $expanded = [];
        foreach ($entries as $entry) {
            if ($entry === Settings::PROXY_NONE) {
                continue;
            }
            if ($entry === Settings::PROXY_CLOUDFLARE) {
                $expanded = array_merge($expanded, self::cloudflareRanges());
            } elseif ($entry === Settings::PROXY_PRIVATE) {
                // Kept as a keyword: matchesAny() asks Store::isPrivate(),
                // which covers ranges wider than ipInCidr() accepts (fc00::/7).
                $expanded[] = Settings::PROXY_PRIVATE;
            } else {
                $expanded[] = $entry;
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return list<string>
     */
    private static function cloudflareRanges(): array
    {
        $ranges = function_exists('apply_filters')
            ? (array) apply_filters('clockwork_companion_cloudflare_ranges', self::CLOUDFLARE_RANGES)
            : self::CLOUDFLARE_RANGES;

        return Settings::sanitizeProxyList($ranges);
    }

    /** CF-Connecting-IP when it holds a valid address (Cloudflare sends exactly one). */
    private static function cloudflareHeader(): ?string
    {
        if (empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return null;
        }
        $cf = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);

        return filter_var($cf, FILTER_VALIDATE_IP) !== false ? $cf : null;
    }

    public static function isCloudflare(string $ip): bool
    {
        return self::matchesAny($ip, self::CLOUDFLARE_RANGES);
    }

    /**
     * @param list<string> $list
     */
    private static function matchesAny(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if ($entry === Settings::PROXY_PRIVATE ? Store::isPrivate($ip) : Store::ipInCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }
}
