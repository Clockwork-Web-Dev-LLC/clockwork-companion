<?php

namespace ClockworkCompanion\Backup;

/**
 * Streams a local backup archive directly to AWS S3 using a presigned PUT URL.
 * Memory efficient: uses curl stream upload (CURLOPT_INFILE) so large files
 * are streamed straight to S3 without loading into PHP memory.
 */
class S3DirectUploader
{
    /** @var callable|null Test callback for unit tests: fn(string $filePath, string $uploadUrl, array $headers, int $size, string $sha256): array|bool */
    public static $testUploader = null;

    /**
     * Upload file to presigned S3 PUT URL.
     *
     * @param  string  $filePath Local file path
     * @param  string  $uploadUrl Presigned S3 PUT URL
     * @param  array<string, string>  $headers Headers to send (e.g. ['x-amz-storage-class' => 'GLACIER_IR'])
     * @return array{ok: bool, http_code: int, size_bytes: int, sha256: string, error?: string}
     */
    public function upload(string $filePath, string $uploadUrl, array $headers = []): array
    {
        if (! file_exists($filePath)) {
            return [
                'ok' => false,
                'http_code' => 0,
                'size_bytes' => 0,
                'sha256' => '',
                'error' => "File not found: {$filePath}",
            ];
        }

        $size = (int) filesize($filePath);
        $sha256 = (string) hash_file('sha256', $filePath);

        if (is_callable(self::$testUploader)) {
            $testRes = call_user_func(self::$testUploader, $filePath, $uploadUrl, $headers, $size, $sha256);
            if (is_array($testRes)) {
                return array_merge([
                    'size_bytes' => $size,
                    'sha256' => $sha256,
                ], $testRes);
            }

            return [
                'ok' => (bool) $testRes,
                'http_code' => 200,
                'size_bytes' => $size,
                'sha256' => $sha256,
            ];
        }

        if (! extension_loaded('curl')) {
            return [
                'ok' => false,
                'http_code' => 0,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'error' => 'PHP curl extension is required for direct S3 streaming.',
            ];
        }

        $fp = @fopen($filePath, 'rb');
        if (! $fp) {
            return [
                'ok' => false,
                'http_code' => 0,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'error' => "Failed to open file for streaming: {$filePath}",
            ];
        }

        $headerList = [];
        foreach ($headers as $k => $v) {
            $headerList[] = "{$k}: {$v}";
        }
        $headerList[] = 'Content-Length: '.$size;

        $ch = curl_init($uploadUrl);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, $size);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerList);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        fclose($fp);
        curl_close($ch);

        $success = in_array($httpCode, [200, 201, 204], true);

        if (! $success) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'error' => "S3 upload failed (HTTP {$httpCode}): {$response} {$curlError}",
            ];
        }

        return [
            'ok' => true,
            'http_code' => $httpCode,
            'size_bytes' => $size,
            'sha256' => $sha256,
        ];
    }
}
