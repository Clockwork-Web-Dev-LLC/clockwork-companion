<?php

namespace ClockworkCompanion\Backup;

/**
 * Downloads an off-site archive from a presigned HTTPS URL to a local destination file.
 * Memory efficient: streams directly to disk via curl (CURLOPT_FILE).
 * Supports resuming partial downloads when a temp file already exists.
 */
class ArchiveDownloader
{
    /** @var callable|null Test callback: fn(string $url, string $destPath, ?callable $progressCallback, ?string $archiveKey): array{ok: bool, http_code: int, bytes_downloaded: int, sha256: string, error?: string} */
    public static $testDownloader = null;

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
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with(strtolower($url), 'https://')) {
            return [
                'ok' => false,
                'http_code' => 0,
                'bytes_downloaded' => 0,
                'sha256' => '',
                'error' => 'Valid HTTPS URL is required for archive download.',
            ];
        }

        if (is_callable(self::$testDownloader)) {
            $testRes = call_user_func(self::$testDownloader, $url, $destPath, $progressCallback, $archiveKey);
            if (is_array($testRes)) {
                return $testRes;
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

        $existingBytes = 0;
        $fileMode = 'wb';
        $rangeHeader = [];

        if (file_exists($destPath)) {
            $existingBytes = (int) filesize($destPath);
            if ($existingBytes > 0) {
                $fileMode = 'ab';
                $rangeHeader = ["Range: bytes={$existingBytes}-"];
            }
        }

        $fp = @fopen($destPath, $fileMode);
        if (! $fp) {
            return [
                'ok' => false,
                'http_code' => 0,
                'bytes_downloaded' => 0,
                'sha256' => '',
                'error' => "Failed to open destination file for writing: {$destPath}",
            ];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if (! empty($rangeHeader)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $rangeHeader);
        }

        if ($progressCallback !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $totalDownload, $downloaded) use ($progressCallback, $existingBytes) {
                if ($totalDownload > 0 || $downloaded > 0) {
                    $total = (int) $totalDownload + $existingBytes;
                    $current = (int) $downloaded + $existingBytes;
                    $progressCallback($current, $total);
                }
            });
        }

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        fclose($fp);
        curl_close($ch);

        // If server replied 416 (Range Not Satisfiable), existing file might already be complete or invalid.
        if ($httpCode === 416 && $existingBytes > 0) {
            @unlink($destPath);
            return $this->download($url, $destPath, $progressCallback, $archiveKey);
        }

        $success = in_array($httpCode, [200, 206], true);
        if (! $success) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'bytes_downloaded' => (int) (file_exists($destPath) ? filesize($destPath) : 0),
                'sha256' => '',
                'error' => "Archive download failed (HTTP {$httpCode}): {$curlError}",
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
}
