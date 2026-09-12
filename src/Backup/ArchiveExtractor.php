<?php

namespace ClockworkCompanion\Backup;

use ZipArchive;

/**
 * Extracts a zip archive to a destination directory.
 * Uses ZipArchive when available; falls back to WordPress PclZip one-shot extraction.
 */
class ArchiveExtractor
{
    /** @var callable|null Test callback for unit tests: fn(string $zipPath, string $destDir): array{ok: bool, files_count: int, error?: string} */
    public static $testExtractor = null;

    /**
     * Extract archive to destination directory.
     *
     * @param  string  $zipPath Path to the zip file.
     * @param  string  $destDir Target extraction directory.
     * @return array{ok: bool, files_count: int, error?: string}
     */
    public function extract(string $zipPath, string $destDir): array
    {
        if (! file_exists($zipPath)) {
            return [
                'ok' => false,
                'files_count' => 0,
                'error' => "Archive file does not exist: {$zipPath}",
            ];
        }

        if (! is_dir($destDir)) {
            if (! @mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
                return [
                    'ok' => false,
                    'files_count' => 0,
                    'error' => "Failed to create destination directory: {$destDir}",
                ];
            }
        }

        if (is_callable(self::$testExtractor)) {
            $testRes = call_user_func(self::$testExtractor, $zipPath, $destDir);
            if (is_array($testRes)) {
                return $testRes;
            }

            return [
                'ok' => (bool) $testRes,
                'files_count' => 1,
            ];
        }

        if (class_exists(ZipArchive::class)) {
            return $this->extractWithZipArchive($zipPath, $destDir);
        }

        return $this->extractWithPclZip($zipPath, $destDir);
    }

    private function extractWithZipArchive(string $zipPath, string $destDir): array
    {
        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            return [
                'ok' => false,
                'files_count' => 0,
                'error' => "Failed to open zip archive with ZipArchive (code: {$openResult})",
            ];
        }

        $numFiles = $zip->numFiles;
        $extracted = $zip->extractTo($destDir);
        $zip->close();

        if (! $extracted) {
            return [
                'ok' => false,
                'files_count' => 0,
                'error' => 'ZipArchive extractTo returned false',
            ];
        }

        return [
            'ok' => true,
            'files_count' => $numFiles,
        ];
    }

    private function extractWithPclZip(string $zipPath, string $destDir): array
    {
        $pclPath = defined('ABSPATH') ? ABSPATH.'wp-admin/includes/class-pclzip.php' : '';
        if ($pclPath === '' || ! is_file($pclPath)) {
            return [
                'ok' => false,
                'files_count' => 0,
                'error' => 'ZipArchive extension is not available in PHP, and WordPress PclZip could not be loaded.',
            ];
        }

        require_once $pclPath;
        if (! class_exists('PclZip')) {
            return [
                'ok' => false,
                'files_count' => 0,
                'error' => 'PclZip class not available.',
            ];
        }

        if (! defined('PCLZIP_OPT_PATH')) {
            define('PCLZIP_OPT_PATH', 77001);
        }

        $archive = new \PclZip($zipPath);
        $list = $archive->extract(PCLZIP_OPT_PATH, $destDir);

        if ($list === 0) {
            return [
                'ok' => false,
                'files_count' => 0,
                'error' => 'PclZip extraction failed: '.$archive->errorInfo(true),
            ];
        }

        return [
            'ok' => true,
            'files_count' => is_array($list) ? count($list) : 0,
        ];
    }
}
