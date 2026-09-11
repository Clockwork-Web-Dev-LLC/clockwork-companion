<?php

namespace ClockworkCompanion\Backup;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Creates a complete WordPress backup archive (.zip).
 * Packages the database dump and wp-content directory (excluding cache & temp files).
 */
class BackupArchiver
{
    /** Default directories to exclude inside wp-content */
    public const DEFAULT_EXCLUDES = [
        'cache',
        'et-cache',
        'w3tc-cache',
        'wflogs',
        'clockwork-backups',
        'updraft',
        'ai1wm-backups',
        'wpvividbackups',
        'backwpup',
        '.git',
        'node_modules',
    ];

    /**
     * Package database dump and site files into a zip archive.
     *
     * @param  string  $zipOutputPath Destination path for zip
     * @param  string  $dbDumpPath Path to generated db.sql or db.sql.gz
     * @param  bool  $includeFiles Whether to include wp-content files
     * @param  array<string>  $customExcludes Additional directory names or patterns to exclude
     * @return array{ok: bool, size_bytes: int, sha256: string, files_count: int, error?: string}
     */
    public function createArchive(
        string $zipOutputPath,
        string $dbDumpPath,
        bool $includeFiles = true,
        array $customExcludes = []
    ): array {
        $dir = dirname($zipOutputPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return [
                'ok' => false,
                'size_bytes' => 0,
                'sha256' => '',
                'files_count' => 0,
                'error' => "Cannot create directory: {$dir}",
            ];
        }

        $entries = $this->collectEntries($dbDumpPath, $includeFiles, $customExcludes);
        if ($entries === []) {
            return [
                'ok' => false,
                'size_bytes' => 0,
                'sha256' => '',
                'files_count' => 0,
                'error' => 'Nothing to archive (no database dump or site files).',
            ];
        }

        if (class_exists('ZipArchive')) {
            $written = $this->writeWithZipArchive($zipOutputPath, $entries);
        } else {
            $written = $this->writeWithPclZip($zipOutputPath, $entries);
        }

        if ($written !== null) {
            return $written;
        }

        $size = (int) @filesize($zipOutputPath);
        $sha256 = $size > 0 ? (string) @hash_file('sha256', $zipOutputPath) : '';

        return [
            'ok' => true,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'files_count' => count($entries),
        ];
    }

    /**
     * @param  array<string>  $customExcludes
     * @return array<int, array{source: string, dest: string}>
     */
    private function collectEntries(string $dbDumpPath, bool $includeFiles, array $customExcludes): array
    {
        $entries = [];

        if (file_exists($dbDumpPath)) {
            $entries[] = [
                'source' => $dbDumpPath,
                'dest' => 'database/'.basename($dbDumpPath),
            ];
        }

        $wpConfigPath = defined('ABSPATH') ? ABSPATH.'wp-config.php' : null;
        if ($wpConfigPath && file_exists($wpConfigPath)) {
            $entries[] = ['source' => $wpConfigPath, 'dest' => 'wp-config.php'];
        } elseif (defined('ABSPATH') && file_exists(dirname(ABSPATH).'/wp-config.php')) {
            $entries[] = ['source' => dirname(ABSPATH).'/wp-config.php', 'dest' => 'wp-config.php'];
        }

        $contentDir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? ABSPATH.'wp-content' : null);
        if ($includeFiles && $contentDir && is_dir($contentDir)) {
            $contentDir = rtrim($contentDir, '/');
            $excludes = array_merge(self::DEFAULT_EXCLUDES, $customExcludes);

            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($contentDir, FilesystemIterator::SKIP_DOTS),
                    function (SplFileInfo $current) use ($excludes) {
                        $name = $current->getFilename();
                        if (str_starts_with($name, '._') || $name === '.DS_Store') {
                            return false;
                        }
                        if ($current->isDir()) {
                            if (in_array(strtolower($name), $excludes, true)) {
                                return false;
                            }
                        }

                        return true;
                    }
                )
            );

            foreach ($iter as $item) {
                /** @var SplFileInfo $item */
                if (! $item->isDir()) {
                    $relative = substr($item->getPathname(), strlen($contentDir) + 1);
                    $entries[] = [
                        'source' => $item->getPathname(),
                        'dest' => 'wp-content/'.$relative,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * @param  array<int, array{source: string, dest: string}>  $entries
     * @return array{ok: bool, size_bytes: int, sha256: string, files_count: int, error?: string}|null
     */
    private function writeWithZipArchive(string $zipOutputPath, array $entries): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipOutputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [
                'ok' => false,
                'size_bytes' => 0,
                'sha256' => '',
                'files_count' => 0,
                'error' => "Cannot create zip archive at {$zipOutputPath}",
            ];
        }

        foreach ($entries as $entry) {
            $zip->addFile($entry['source'], $entry['dest']);
        }
        $zip->close();

        return null;
    }

    /**
     * Fallback for hosts without the php-zip extension. WordPress core ships PclZip.
     *
     * @param  array<int, array{source: string, dest: string}>  $entries
     * @return array{ok: bool, size_bytes: int, sha256: string, files_count: int, error?: string}|null
     */
    private function writeWithPclZip(string $zipOutputPath, array $entries): ?array
    {
        $pclPath = defined('ABSPATH') ? ABSPATH.'wp-admin/includes/class-pclzip.php' : '';
        if ($pclPath === '' || ! is_file($pclPath)) {
            return [
                'ok' => false,
                'size_bytes' => 0,
                'sha256' => '',
                'files_count' => 0,
                'error' => 'ZipArchive extension is not available in PHP, and WordPress PclZip could not be loaded.',
            ];
        }

        require_once $pclPath;
        if (! class_exists('PclZip')) {
            return [
                'ok' => false,
                'size_bytes' => 0,
                'sha256' => '',
                'files_count' => 0,
                'error' => 'ZipArchive extension is not available in PHP, and WordPress PclZip could not be loaded.',
            ];
        }

        if (is_file($zipOutputPath)) {
            @unlink($zipOutputPath);
        }

        $archive = new \PclZip($zipOutputPath);
        $list = [];
        foreach ($entries as $entry) {
            $list[] = [
                PCLZIP_ATT_FILE_NAME => $entry['source'],
                PCLZIP_ATT_FILE_NEW_FULL_NAME => $entry['dest'],
            ];
        }
        $result = $archive->create($list);
        if ($result == 0) {
            return [
                'ok' => false,
                'size_bytes' => 0,
                'sha256' => '',
                'files_count' => 0,
                'error' => 'PclZip failed: '.$archive->errorInfo(true),
            ];
        }

        return null;
    }
}
