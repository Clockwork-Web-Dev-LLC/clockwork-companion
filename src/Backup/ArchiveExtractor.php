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

        // Zip-slip guard: ZipArchive::extractTo sanitizes traversal entries, but the
        // PclZip fallback does not. Validate entry names up front on BOTH paths.
        $unsafeEntry = $this->findUnsafeEntryName($zipPath);
        if ($unsafeEntry !== null) {
            return [
                'ok' => false,
                'files_count' => 0,
                'error' => "unsafe_archive: entry '{$unsafeEntry}' contains an unsafe path",
            ];
        }

        if (class_exists(ZipArchive::class)) {
            return $this->extractWithZipArchive($zipPath, $destDir);
        }

        return $this->extractWithPclZip($zipPath, $destDir);
    }

    /**
     * True when a zip entry name could escape the extraction directory:
     * a '..' path component, an absolute path, or a Windows drive prefix.
     */
    public static function isUnsafeEntryName(string $name): bool
    {
        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/')) {
            return true;
        }

        if (preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            return true;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * List archive entry names (ZipArchive or PclZip) and return the first unsafe
     * name found, or null when all entries are safe (or the listing could not be
     * produced — the subsequent extraction will surface its own error then).
     */
    private function findUnsafeEntryName(string $zipPath): ?string
    {
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return null;
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
                $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if ($name !== '' && self::isUnsafeEntryName($name)) {
                    $zip->close();

                    return $name;
                }
            }

            $zip->close();

            return null;
        }

        if (! $this->loadPclZip()) {
            return null;
        }

        $archive = new \PclZip($zipPath);
        $entries = $archive->listContent();
        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            $name = is_array($entry) ? (string) ($entry['filename'] ?? '') : '';
            if ($name !== '' && self::isUnsafeEntryName($name)) {
                return $name;
            }
        }

        return null;
    }

    private function loadPclZip(): bool
    {
        if (class_exists('PclZip')) {
            return true;
        }

        $pclPath = defined('ABSPATH') ? ABSPATH.'wp-admin/includes/class-pclzip.php' : '';
        if ($pclPath === '' || ! is_file($pclPath)) {
            return false;
        }

        require_once $pclPath;

        return class_exists('PclZip');
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
        if (! $this->loadPclZip()) {
            return [
                'ok' => false,
                'files_count' => 0,
                'error' => 'ZipArchive extension is not available in PHP, and WordPress PclZip could not be loaded.',
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
