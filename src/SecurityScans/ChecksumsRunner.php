<?php

namespace ClockworkCompanion\SecurityScans;

use ClockworkCompanion\ActionLog\Repository as ActionLogRepository;

/**
 * Companion-side counterpart to Clockwork's WpCoreChecksumVerifier.
 *
 * Runs `wp core verify-checksums` from the WP install itself rather than over
 * SSH from Clockwork. Two paths:
 *   - shell-out to a wp-cli binary if one is available on PATH (preferred);
 *   - PHP-side fetch of api.wordpress.org/core/checksums and SHA256 each file
 *     (fallback for hosts without wp-cli or with shell_exec disabled).
 *
 * On hosts where neither path works (rare — usually shared hosting that's
 * locked PHP down hard), `isAvailable()` returns false so the UI can grey
 * the card with a clear "not available on this host" message instead of
 * showing a confusing failure.
 */
class ChecksumsRunner
{
    private const CHECKSUMS_API = 'https://api.wordpress.org/core/checksums/1.0/';

    /**
     * Files WordPress ships but that hosts and hardening guides commonly
     * remove because they leak the WP version. Absence is intentional, not
     * tampering — exclude from the "missing" tally so a hardened install
     * doesn't flag a clean scan as failing.
     */
    private const EXPECTED_ABSENT = [
        'license.txt',
        'readme.html',
        'wp-config-sample.php',
    ];

    /**
     * Whether this runner can do anything useful on the current host. Used by
     * the Security admin page to decide whether to render the Run button or
     * grey the card with a "host can't run this" message.
     */
    public static function isAvailable(): bool
    {
        return self::wpCliPath() !== null || self::canDoPhpVerification();
    }

    /**
     * Run the verification and write a security_scan row. Returns a summary
     * string for the flash message.
     */
    public function runAndPersist(): string
    {
        $started = microtime(true);

        if ($cli = self::wpCliPath()) {
            $result = $this->runViaWpCli($cli);
        } elseif (self::canDoPhpVerification()) {
            $result = $this->runViaPhp();
        } else {
            $result = [
                'status' => 'failed',
                'summary' => 'Core checksum verification is not available on this host.',
                'details' => ['status' => 'failed', 'reason' => 'no_runner'],
                'error' => 'no_runner',
                'modified_files_count' => 0,
            ];
        }

        $elapsed = (int) ((microtime(true) - $started) * 1000);

        ActionLogRepository::insert([
            'action_type' => 'security_scan',
            'target' => 'core_checksums',
            'summary' => $result['summary'],
            'details' => $result['details'],
            'ok' => $result['status'] !== 'failed',
            'error' => $result['error'],
            'elapsed_ms' => $elapsed,
            'actor' => 'client',
            'care_plan_enabled' => ActionLogRepository::latestCarePlanFlag(),
        ]);

        return $result['summary'];
    }

    /**
     * Try the wp-cli binary path. We don't actually shell-out by default because
     * many shared hosts disable proc_open / shell_exec. PHP-side verification is
     * preferred unless the host is one we control. This method is kept so a
     * future "I trust shell_exec on this site" toggle can flip to it.
     */
    private static function wpCliPath(): ?string
    {
        // Disabled by default; PHP-side path is portable and fast enough.
        // Re-enable by setting CLOCKWORK_COMPANION_USE_WPCLI=1 in wp-config.php.
        if (! defined('CLOCKWORK_COMPANION_USE_WPCLI') || ! CLOCKWORK_COMPANION_USE_WPCLI) {
            return null;
        }
        if (! function_exists('shell_exec')) {
            return null;
        }
        $candidates = ['/usr/local/bin/wp', '/usr/bin/wp', '/opt/homebrew/bin/wp'];
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array{status: string, summary: string, details: array<string, mixed>, error: ?string, modified_files_count: int}
     */
    private function runViaWpCli(string $bin): array
    {
        $abspath = ABSPATH;
        $cmd = escapeshellarg($bin).' core verify-checksums --path='.escapeshellarg($abspath).' 2>&1';
        /** @var string|null $raw */
        $raw = @shell_exec($cmd);
        $raw = is_string($raw) ? $raw : '';

        return $this->parseWarningLines($raw, $abspath);
    }

    private static function canDoPhpVerification(): bool
    {
        // Need to know the WP version (always available) and be able to fetch
        // the manifest. wp_remote_get is the WP HTTP wrapper; it's always there.
        return function_exists('wp_remote_get');
    }

    /**
     * Pure-PHP verification: fetch the manifest from api.wordpress.org and
     * SHA256 each tracked file under ABSPATH. Mirrors what wp-cli does
     * internally, scoped to wp-admin/, wp-includes/, and the top-level
     * loader files (wp-cli skips wp-content/ — themes/plugins/uploads are
     * not in the manifest).
     *
     * @return array{status: string, summary: string, details: array<string, mixed>, error: ?string, modified_files_count: int}
     */
    private function runViaPhp(): array
    {
        global $wp_version;
        $version = is_string($wp_version) ? $wp_version : (string) get_bloginfo('version');
        $locale = (string) get_locale();

        $url = self::CHECKSUMS_API.'?version='.urlencode($version).'&locale='.urlencode($locale);
        $resp = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            $err = is_wp_error($resp) ? $resp->get_error_message() : 'http_'.wp_remote_retrieve_response_code($resp);

            return [
                'status' => 'failed',
                'summary' => 'Could not fetch the WordPress.org checksum manifest.',
                'details' => ['status' => 'failed', 'reason' => 'manifest_fetch_failed', 'http_error' => $err],
                'error' => substr((string) $err, 0, 480),
                'modified_files_count' => 0,
            ];
        }

        $payload = json_decode((string) wp_remote_retrieve_body($resp), true);
        $checksums = is_array($payload['checksums'] ?? null) ? $payload['checksums'] : null;
        if (! is_array($checksums)) {
            return [
                'status' => 'failed',
                'summary' => 'WordPress.org returned no manifest for version '.$version.'.',
                'details' => ['status' => 'failed', 'reason' => 'no_manifest_for_version', 'version' => $version],
                'error' => 'no_manifest_for_version',
                'modified_files_count' => 0,
            ];
        }

        $modified = [];
        $missing = [];
        foreach ($checksums as $relative => $expected) {
            // wp-cli skips wp-content (themes/plugins/uploads). Match that scope.
            if (str_starts_with((string) $relative, 'wp-content/')) {
                continue;
            }
            $abs = ABSPATH.$relative;
            if (! is_readable($abs)) {
                // license.txt / readme.html etc. are commonly stripped for
                // hardening; their absence isn't tampering signal.
                if (in_array($relative, self::EXPECTED_ABSENT, true)) {
                    continue;
                }
                $missing[] = $relative;

                continue;
            }
            // The /1.0/ checksums endpoint returns MD5 hashes; some other
            // wp.org endpoints return SHA256. Pick the algorithm based on the
            // expected digest's length so this code keeps working if the
            // endpoint changes shape (32 chars = md5, 64 chars = sha256).
            $algo = strlen((string) $expected) === 64 ? 'sha256' : 'md5';
            $hash = @hash_file($algo, $abs);
            if ($hash !== $expected) {
                $modified[] = $relative;
            }
        }

        $total = count($modified) + count($missing);

        if ($total === 0) {
            return [
                'status' => 'clean',
                'summary' => 'All core files match WordPress.org checksums.',
                'details' => [
                    'status' => 'clean',
                    'wp_version' => $version,
                    'files_checked' => count($checksums),
                ],
                'error' => null,
                'modified_files_count' => 0,
            ];
        }

        $bits = [];
        if ($modified !== []) {
            $bits[] = count($modified).' modified';
        }
        if ($missing !== []) {
            $bits[] = count($missing).' missing';
        }

        return [
            'status' => 'issues_found',
            'summary' => 'Core file integrity issue — '.implode(', ', $bits).'.',
            'details' => [
                'status' => 'issues_found',
                'wp_version' => $version,
                'modified' => $modified,
                'missing' => $missing,
                'modified_files_count' => $total,
            ],
            'error' => null,
            'modified_files_count' => $total,
        ];
    }

    /**
     * Parse wp-cli's "Warning: File ..." lines. Same shape Clockwork's
     * WpCoreChecksumVerifier produces server-side.
     *
     * @return array{status: string, summary: string, details: array<string, mixed>, error: ?string, modified_files_count: int}
     */
    private function parseWarningLines(string $raw, string $wpPath): array
    {
        $modified = [];
        $missing = [];
        $shouldNotExist = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            // Match both wp-cli phrasings — older versions say "is missing",
            // newer ones say "doesn't exist". Without both, a stripped-
            // readme.html site reaches no finding and the caller treats the
            // non-zero exit code as a generic failure.
            if (preg_match('/^(?:Warning:\s+)?File doesn\'t verify against checksum:\s*(.+)$/', $line, $m)) {
                $modified[] = trim($m[1]);
            } elseif (preg_match('/^(?:Warning:\s+)?File (?:is missing|doesn\'t exist):\s*(.+)$/', $line, $m)) {
                $missing[] = trim($m[1]);
            } elseif (preg_match('/^(?:Warning:\s+)?File should not exist:\s*(.+)$/', $line, $m)) {
                $shouldNotExist[] = trim($m[1]);
            }
        }

        // Filter hardening-friendly absences out of "missing".
        $missing = array_values(array_filter(
            $missing,
            fn (string $f) => ! in_array($f, self::EXPECTED_ABSENT, true)
        ));

        $total = count($modified) + count($missing) + count($shouldNotExist);

        if ($total > 0) {
            $bits = [];
            if ($modified !== []) {
                $bits[] = count($modified).' modified';
            }
            if ($missing !== []) {
                $bits[] = count($missing).' missing';
            }
            if ($shouldNotExist !== []) {
                $bits[] = count($shouldNotExist).' unexpected';
            }

            return [
                'status' => 'issues_found',
                'summary' => 'Core file integrity issue — '.implode(', ', $bits).'.',
                'details' => [
                    'status' => 'issues_found',
                    'modified' => $modified,
                    'missing' => $missing,
                    'should_not_exist' => $shouldNotExist,
                    'wp_path' => $wpPath,
                    'modified_files_count' => $total,
                ],
                'error' => null,
                'modified_files_count' => $total,
            ];
        }

        return [
            'status' => 'clean',
            'summary' => 'All core files match WordPress.org checksums.',
            'details' => [
                'status' => 'clean',
                'wp_path' => $wpPath,
            ],
            'error' => null,
            'modified_files_count' => 0,
        ];
    }
}
