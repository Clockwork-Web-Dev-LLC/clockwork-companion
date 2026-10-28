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
     * File extensions that are already compressed. Storing them with CM_STORE
     * avoids burning 5x-10x CPU deflating media/gzip files and prevents host
     * gateway timeouts on media-heavy sites.
     */
    public const STORE_ONLY_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico',
        'mp4', 'm4v', 'mov', 'webm', 'avi', 'mp3', 'ogg', 'wav', 'flac',
        'pdf', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    /**
     * Maximum files queued before flushing/re-opening ZipArchive.
     * PHP's libzip holds open file descriptors for every addFile() call until
     * close(); batching at 300 keeps us safely below the OS ulimit -n (1024).
     */
    public const ZIP_BATCH_SIZE = 300;

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

        clearstatcache(true, $zipOutputPath);
        if (! is_file($zipOutputPath)) {
            return [
                'ok' => false,
                'size_bytes' => 0,
                'sha256' => '',
                'files_count' => 0,
                'error' => "Archive creation failed to write file: {$zipOutputPath}",
            ];
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
        if ($wpConfigPath && file_exists($wpConfigPath) && is_readable($wpConfigPath)) {
            $entries[] = ['source' => $wpConfigPath, 'dest' => 'wp-config.php'];
        } elseif (defined('ABSPATH') && file_exists(dirname(ABSPATH).'/wp-config.php') && is_readable(dirname(ABSPATH).'/wp-config.php')) {
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
                        if ($current->isLink()) {
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
                if (! $item->isDir() && $item->isReadable()) {
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
        if (is_file($zipOutputPath)) {
            @unlink($zipOutputPath);
        }

        $chunks = array_chunk($entries, self::ZIP_BATCH_SIZE);

        foreach ($chunks as $chunkIndex => $batch) {
            $zip = new ZipArchive();
            $flags = $chunkIndex === 0
                ? (ZipArchive::CREATE | ZipArchive::OVERWRITE)
                : ZipArchive::CREATE;

            if ($zip->open($zipOutputPath, $flags) !== true) {
                return [
                    'ok' => false,
                    'size_bytes' => 0,
                    'sha256' => '',
                    'files_count' => 0,
                    'error' => "Cannot open zip archive at {$zipOutputPath} (batch {$chunkIndex})",
                ];
            }

            foreach ($batch as $entry) {
                if (! is_file($entry['source']) || ! is_readable($entry['source'])) {
                    continue;
                }
                if ($zip->addFile($entry['source'], $entry['dest'])) {
                    $ext = strtolower((string) pathinfo($entry['source'], PATHINFO_EXTENSION));
                    if (in_array($ext, self::STORE_ONLY_EXTENSIONS, true) && method_exists($zip, 'setCompressionName')) {
                        @$zip->setCompressionName($entry['dest'], ZipArchive::CM_STORE);
                    }
                }
            }

            if (! @$zip->close()) {
                return [
                    'ok' => false,
                    'size_bytes' => 0,
                    'sha256' => '',
                    'files_count' => 0,
                    'error' => "Failed closing/flushing zip archive at {$zipOutputPath} (batch {$chunkIndex})",
                ];
            }
        }

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
