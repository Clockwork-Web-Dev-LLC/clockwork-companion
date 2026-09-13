<?php

namespace ClockworkCompanion\Backup;

/**
 * Downloads an off-site archive from a presigned HTTPS URL to a local destination file.
 * Memory efficient: streams directly to disk via curl (CURLOPT_FILE).
 *
 * Resume support: the in-progress download is written to a partial file keyed by the
 * archive key (restore-dl-<md5(archive_key)>.zip.part next to the destination), so a
 * failed download leaves a resumable partial behind. A later download call for the
 * same archive key finds the partial and resumes with a Range header from its size.
 * Only on a completed download is the partial renamed to the final destination path.
 */
class ArchiveDownloader
{
    /** @var callable|null Test callback: fn(string $url, string $destPath, ?callable $progressCallback, ?string $archiveKey, string $partialPath, int $resumeOffset): array{ok: bool, http_code: int, bytes_downloaded: int, sha256: string, error?: string} */
    public static $testDownloader = null;

    /** @var list<string>|null Hosts treated as public in tests (skip DNS). */
    public static ?array $testAllowHosts = null;

    /**
     * Compute the resumable partial-file path for a download.
     * Keyed by the archive key so a re-staged download of the same archive can
     * resume, regardless of the per-request staged id embedded in $destPath.
     * The "restore-" prefix keeps it inside Paths::sweepStale()'s cleanup patterns.
     */
    public static function partialPath(string $destPath, ?string $archiveKey = null): string
    {
        if ($archiveKey !== null && $archiveKey !== '') {
            return rtrim(dirname($destPath), '/\\').'/restore-dl-'.md5($archiveKey).'.zip.part';
        }

        return $destPath.'.part';
    }

    /**
     * Download an archive to the target destination path.
     *
     * @param  string  $url Presigned HTTPS download URL
     * @param  string  $destPath Local path to save the downloaded archive
     * @param  callable|null  $progressCallback Callback receiving (int $downloadedBytes, int $totalBytes)
     * @param  string|null  $archiveKey Optional archive key identifier for resume tracking
     * @return array{ok: bool, http_code: int, bytes_downloaded: int, sha256: string, error?: string}
     */
    public function download(string $url, string $destPath, ?callable $progressCallback = null, ?string $archiveKey = null): array
    {
        if (! self::isSafeHttpsDownloadUrl($url)) {
            return [
                'ok' => false,
                'http_code' => 0,
                'bytes_downloaded' => 0,
                'sha256' => '',
                'error' => 'Valid public HTTPS URL is required for archive download.',
            ];
        }

        $partialPath = self::partialPath($destPath, $archiveKey);
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

            return $this->download($url, $destPath, $progressCallback, $archiveKey);
        }

        // Server ignored our Range and replied 200 with the full body, which we appended
        // after the partial bytes. The file is corrupt: truncate (discard) and restart
        // from zero. Never keep a full body appended after a partial.
        if ($httpCode === 200 && $resumeOffset > 0) {
            @unlink($partialPath);

            return $this->download($url, $destPath, $progressCallback, $archiveKey);
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
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        if (self::$testAllowHosts !== null && in_array(strtolower($host), self::$testAllowHosts, true)) {
            return true;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : self::resolveHostIps($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
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
