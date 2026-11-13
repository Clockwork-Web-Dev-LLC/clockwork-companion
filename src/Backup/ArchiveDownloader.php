<?php

namespace ClockworkCompanion\Backup;

/**
 * Downloads an off-site archive from a presigned HTTPS URL to a local destination file.
 * Memory efficient: streams directly to disk via curl (CURLOPT_FILE).
 *
 * Resume support: the in-progress download is written to a partial file, and a failed
 * download leaves it behind. The caller chooses that file's name (BackupRestoreRoute
 * uses a random one recorded in RestoreState, never one derived from the archive key,
 * which anyone who knows the site's domain and the date could compute) and passes the
 * same path again to resume with a Range header from its size. Only on a completed
 * download is the partial renamed to the final destination path.
 */
class ArchiveDownloader
{
    /** @var callable|null Test callback: fn(string $url, string $destPath, ?callable $progressCallback, ?string $archiveKey, string $partialPath, int $resumeOffset): array{ok: bool, http_code: int, bytes_downloaded: int, sha256: string, error?: string} */
    public static $testDownloader = null;

    /** @var list<string>|null Hosts treated as public in tests (skip DNS). */
    public static ?array $testAllowHosts = null;

    /**
     * Default partial-file path when the caller does not choose one.
     */
    public static function partialPath(string $destPath): string
    {
        return $destPath.'.part';
    }

    /**
     * Download an archive to the target destination path.
     *
     * @param  string  $url Presigned HTTPS download URL
     * @param  string  $destPath Local path to save the downloaded archive
     * @param  callable|null  $progressCallback Callback receiving (int $downloadedBytes, int $totalBytes)
     * @param  string|null  $archiveKey Archive key (passed through to the test seam only)
     * @param  string|null  $partialPath Where the in-progress download is kept; pass the same path to resume
     * @return array{ok: bool, http_code: int, bytes_downloaded: int, sha256: string, error?: string}
     */
    public function download(string $url, string $destPath, ?callable $progressCallback = null, ?string $archiveKey = null, ?string $partialPath = null): array
    {
        $target = self::resolveSafeHttpsTarget($url);
        if ($target === null) {
            return [
                'ok' => false,
                'http_code' => 0,
                'bytes_downloaded' => 0,
                'sha256' => '',
                'error' => 'Valid public HTTPS URL is required for archive download.',
            ];
        }

        $partialPath = $partialPath !== null && $partialPath !== '' ? $partialPath : self::partialPath($destPath);
        $resumeOffset = file_exists($partialPath) ? (int) filesize($partialPath) : 0;

        if (is_callable(self::$testDownloader)) {
            $testRes = call_user_func(self::$testDownloader, $url, $destPath, $progressCallback, $archiveKey, $partialPath, $resumeOffset);

            if (is_array($testRes)) {
                if (($testRes['ok'] ?? false) && ! file_exists($destPath) && file_exists($partialPath)) {
                    @rename($partialPath, $destPath);
                }

                return $testRes;
            }

            if ((bool) $testRes && ! file_exists($destPath) && file_exists($partialPath)) {
                @rename($partialPath, $destPath);
            }

            $size = file_exists($destPath) ? (int) filesize($destPath) : 0;
            $hash = file_exists($destPath) ? (string) hash_file('sha256', $destPath) : '';

            return [
                'ok' => (bool) $testRes,
                'http_code' => 200,
                'bytes_downloaded' => $size,
                'sha256' => $hash,
            ];
        }

        if (! extension_loaded('curl')) {
            return [
                'ok' => false,
                'http_code' => 0,
                'bytes_downloaded' => 0,
                'sha256' => '',
                'error' => 'PHP curl extension is required for archive download.',
            ];
        }

        $fileMode = $resumeOffset > 0 ? 'ab' : 'wb';
        $rangeHeader = $resumeOffset > 0 ? ["Range: bytes={$resumeOffset}-"] : [];

        $fp = @fopen($partialPath, $fileMode);
        if (! $fp) {
            return [
                'ok' => false,
                'http_code' => 0,
                'bytes_downloaded' => 0,
                'sha256' => '',
                'error' => "Failed to open download file for writing: {$partialPath}",
            ];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_RESOLVE') && $target['ips'] !== [] && filter_var($target['host'], FILTER_VALIDATE_IP) === false) {
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$target['host']}:{$target['port']}:{$target['ips'][0]}"]);
        }

        if (! empty($rangeHeader)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $rangeHeader);
        }

        if ($progressCallback !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $totalDownload, $downloaded) use ($progressCallback, $resumeOffset) {
                if ($totalDownload > 0 || $downloaded > 0) {
                    $total = (int) $totalDownload + $resumeOffset;
                    $current = (int) $downloaded + $resumeOffset;
                    $progressCallback($current, $total);
                }
            });
        }

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        fclose($fp);
        curl_close($ch);

        // If server replied 416 (Range Not Satisfiable), the partial is unusable: discard and restart.
        if ($httpCode === 416 && $resumeOffset > 0) {
            @unlink($partialPath);

            return $this->download($url, $destPath, $progressCallback, $archiveKey, $partialPath);
        }

        // Server ignored our Range and replied 200 with the full body, which we appended
        // after the partial bytes. The file is corrupt: truncate (discard) and restart
        // from zero. Never keep a full body appended after a partial.
        if ($httpCode === 200 && $resumeOffset > 0) {
            @unlink($partialPath);

            return $this->download($url, $destPath, $progressCallback, $archiveKey, $partialPath);
        }

        if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
            @unlink($partialPath);

            return [
                'ok' => false,
                'http_code' => $httpCode,
                'bytes_downloaded' => 0,
                'sha256' => '',
                'error' => 'Archive download refused a redirect. Use a direct HTTPS object URL.',
            ];
        }

        $success = in_array($httpCode, [200, 206], true);
        if (! $success) {
            // Keep the partial file on disk so a later attempt can resume.
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'bytes_downloaded' => (int) (file_exists($partialPath) ? filesize($partialPath) : 0),
                'sha256' => '',
                'error' => "Archive download failed (HTTP {$httpCode}): {$curlError}",
            ];
        }

        // Completed download: promote the partial to the final destination path.
        if (! @rename($partialPath, $destPath)) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'bytes_downloaded' => (int) (file_exists($partialPath) ? filesize($partialPath) : 0),
                'sha256' => '',
                'error' => "Failed to move completed download into place: {$destPath}",
            ];
        }

        $finalSize = (int) filesize($destPath);
        $finalSha256 = (string) hash_file('sha256', $destPath);

        return [
            'ok' => true,
            'http_code' => $httpCode,
            'bytes_downloaded' => $finalSize,
            'sha256' => $finalSha256,
        ];
    }

    /**
     * HTTPS only, and the host must not resolve (or be) a private / reserved
     * address. HMAC already gates this route; this stops a stolen secret from
     * turning Companion into a metadata/SSRF client.
     */
    public static function isSafeHttpsDownloadUrl(string $url): bool
    {
        return self::resolveSafeHttpsTarget($url) !== null;
    }

    /**
     * Resolve and validate a public HTTPS endpoint once so cURL can pin the
     * validated IP via CURLOPT_RESOLVE (preventing DNS-rebinding TOCTOU).
     *
     * @return array{host: string, port: int, ips: list<string>}|null
     */
    public static function resolveSafeHttpsTarget(string $url): ?array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        $cleanHost = trim($host, '[]');

        if (self::$testAllowHosts !== null && in_array(strtolower($cleanHost), self::$testAllowHosts, true)) {
            return ['host' => $cleanHost, 'port' => $port, 'ips' => []];
        }

        $ips = filter_var($cleanHost, FILTER_VALIDATE_IP) !== false
            ? [$cleanHost]
            : self::resolveHostIps($cleanHost);

        if ($ips === []) {
            return null;
        }

        $ipFlags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            $ipFlags |= FILTER_FLAG_GLOBAL_RANGE;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, $ipFlags) === false) {
                return null;
            }
        }

        return ['host' => $cleanHost, 'port' => $port, 'ips' => $ips];
    }

    /** @return list<string> */
    private static function resolveHostIps(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
