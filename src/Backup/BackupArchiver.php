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
     * Package database dump and site files into a zip archive with manifest.json.
     *
     * @param  string  $zipOutputPath Destination path for zip
     * @param  string  $dbDumpPath Path to generated db.sql or db.sql.gz
     * @param  bool  $includeFiles Whether to include wp-content files
     * @param  array<string>  $customExcludes Additional directory names or patterns to exclude
     * @param  array<string, mixed>  $dbStats Database dump statistics (table count, rows, sizes)
     * @return array{ok: bool, size_bytes: int, sha256: string, files_count: int, manifest?: array<string, mixed>, error?: string}
     */
    public function createArchive(
        string $zipOutputPath,
        string $dbDumpPath,
        bool $includeFiles = true,
        array $customExcludes = [],
        array $dbStats = []
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

        $stats = [
            'total_uncompressed_bytes' => 0,
            'uploads_count' => 0,
            'uploads_bytes' => 0,
            'plugins_count' => 0,
            'plugins_bytes' => 0,
            'plugin_slugs' => [],
            'themes_count' => 0,
            'themes_bytes' => 0,
            'theme_slugs' => [],
            'other_count' => 0,
            'other_bytes' => 0,
            'has_wp_config' => false,
            'has_htaccess' => false,
            'skipped_dirs_count' => 0,
        ];

        $excludes = array_merge(self::DEFAULT_EXCLUDES, $customExcludes);
        $entries = $this->collectEntries($dbDumpPath, $includeFiles, $customExcludes, $stats);
        if ($entries === []) {
            return [
                'ok' => false,
                'size_bytes' => 0,
                'sha256' => '',
                'files_count' => 0,
                'error' => 'Nothing to archive (no database dump or site files).',
            ];
        }

        $manifest = $this->buildManifest($stats, $dbStats, $excludes, count($entries));
        $tempManifestPath = null;

        if (class_exists('ZipArchive')) {
            $written = $this->writeWithZipArchive($zipOutputPath, $entries);
        } else {
            // For PclZip fallback, write manifest to temp file and include in entries before packing
            $tempManifestPath = $dir.'/manifest-'.bin2hex(random_bytes(4)).'.json';
            @file_put_contents($tempManifestPath, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $entriesWithManifest = $entries;
            $entriesWithManifest[] = [
                'source' => $tempManifestPath,
                'dest' => 'manifest.json',
            ];
            $written = $this->writeWithPclZip($zipOutputPath, $entriesWithManifest);
        }

        if ($tempManifestPath && file_exists($tempManifestPath)) {
            @unlink($tempManifestPath);
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

        $archiveSize = (int) @filesize($zipOutputPath);
        $uncompressed = (int) ($manifest['summary']['total_uncompressed_bytes'] ?? 0);
        $saved = max(0, $uncompressed - $archiveSize);
        $ratio = $uncompressed > 0 ? round(($saved / $uncompressed) * 100, 1).'%' : '0%';

        $manifest['summary']['archive_size_bytes'] = $archiveSize;
        $manifest['summary']['compression_ratio'] = $ratio;
        $manifest['summary']['total_files'] = count($entries) + 1;

        // When using ZipArchive, inject the final manifest with updated size and ratio
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipOutputPath) === true) {
                $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $zip->close();
            }
            clearstatcache(true, $zipOutputPath);
            $archiveSize = (int) @filesize($zipOutputPath);
            $manifest['summary']['archive_size_bytes'] = $archiveSize;
        }

        $sha256 = $archiveSize > 0 ? (string) @hash_file('sha256', $zipOutputPath) : '';

        return [
            'ok' => true,
            'size_bytes' => $archiveSize,
            'sha256' => $sha256,
            'files_count' => $manifest['summary']['total_files'],
            'manifest' => $manifest,
        ];
    }

    /**
     * @param  array<string>  $customExcludes
     * @param  array<string, mixed>  $stats Reference to stats collector
     * @return array<int, array{source: string, dest: string}>
     */
    private function collectEntries(string $dbDumpPath, bool $includeFiles, array $customExcludes, array &$stats): array
    {
        $entries = [];

        if (file_exists($dbDumpPath)) {
            $entries[] = [
                'source' => $dbDumpPath,
                'dest' => 'database/'.basename($dbDumpPath),
            ];
            $stats['total_uncompressed_bytes'] += (int) @filesize($dbDumpPath);
        }

        $wpConfigPath = defined('ABSPATH') ? ABSPATH.'wp-config.php' : null;
        if ($wpConfigPath && file_exists($wpConfigPath) && is_readable($wpConfigPath)) {
            $entries[] = ['source' => $wpConfigPath, 'dest' => 'wp-config.php'];
            $stats['has_wp_config'] = true;
            $stats['total_uncompressed_bytes'] += (int) @filesize($wpConfigPath);
        } elseif (defined('ABSPATH') && file_exists(dirname(ABSPATH).'/wp-config.php') && is_readable(dirname(ABSPATH).'/wp-config.php')) {
            $parentConfig = dirname(ABSPATH).'/wp-config.php';
            $entries[] = ['source' => $parentConfig, 'dest' => 'wp-config.php'];
            $stats['has_wp_config'] = true;
            $stats['total_uncompressed_bytes'] += (int) @filesize($parentConfig);
        }

        $htaccessPath = defined('ABSPATH') ? ABSPATH.'.htaccess' : null;
        if ($htaccessPath && file_exists($htaccessPath) && is_readable($htaccessPath)) {
            $entries[] = ['source' => $htaccessPath, 'dest' => '.htaccess'];
            $stats['has_htaccess'] = true;
            $stats['total_uncompressed_bytes'] += (int) @filesize($htaccessPath);
        }

        $contentDir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? ABSPATH.'wp-content' : null);
        if ($includeFiles && $contentDir && is_dir($contentDir)) {
            $contentDir = rtrim($contentDir, '/');
            $excludes = array_merge(self::DEFAULT_EXCLUDES, $customExcludes);
            $skippedDirs = 0;

            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($contentDir, FilesystemIterator::SKIP_DOTS),
                    function (SplFileInfo $current) use ($excludes, &$skippedDirs) {
                        $name = $current->getFilename();
                        if (str_starts_with($name, '._') || $name === '.DS_Store') {
                            return false;
                        }
                        if ($current->isLink()) {
                            return false;
                        }
                        if ($current->isDir()) {
                            if (in_array(strtolower($name), $excludes, true)) {
                                $skippedDirs++;

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
                    $pathname = $item->getPathname();
                    $relative = substr($pathname, strlen($contentDir) + 1);
                    $fileSize = (int) @filesize($pathname);
                    $stats['total_uncompressed_bytes'] += $fileSize;

                    if (str_starts_with($relative, 'uploads/') || $relative === 'uploads') {
                        $stats['uploads_count']++;
                        $stats['uploads_bytes'] += $fileSize;
                    } elseif (str_starts_with($relative, 'plugins/')) {
                        $stats['plugins_count']++;
                        $stats['plugins_bytes'] += $fileSize;
                        $sub = substr($relative, 8);
                        $slashPos = strpos($sub, '/');
                        $slug = $slashPos !== false ? substr($sub, 0, $slashPos) : $sub;
                        if ($slug !== '') {
                            $stats['plugin_slugs'][$slug] = true;
                        }
                    } elseif (str_starts_with($relative, 'themes/')) {
                        $stats['themes_count']++;
                        $stats['themes_bytes'] += $fileSize;
                        $sub = substr($relative, 7);
                        $slashPos = strpos($sub, '/');
                        $slug = $slashPos !== false ? substr($sub, 0, $slashPos) : $sub;
                        if ($slug !== '') {
                            $stats['theme_slugs'][$slug] = true;
                        }
                    } else {
                        $stats['other_count']++;
                        $stats['other_bytes'] += $fileSize;
                    }

                    $entries[] = [
                        'source' => $pathname,
                        'dest' => 'wp-content/'.$relative,
                    ];
                }
            }

            $stats['skipped_dirs_count'] = $skippedDirs;
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $dbStats
     * @param  array<string>  $excludes
     * @return array<string, mixed>
     */
    private function buildManifest(array $stats, array $dbStats, array $excludes, int $entriesCount): array
    {
        $domain = '';
        if (function_exists('home_url')) {
            $domain = (string) parse_url((string) home_url(), PHP_URL_HOST);
        } elseif (defined('CLOCKWORK_SITE_DOMAIN')) {
            $domain = (string) constant('CLOCKWORK_SITE_DOMAIN');
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            $domain = (string) $_SERVER['HTTP_HOST'];
        }

        global $wp_version;

        $pluginList = array_values(array_keys($stats['plugin_slugs'] ?? []));
        sort($pluginList);

        $themeList = array_values(array_keys($stats['theme_slugs'] ?? []));
        sort($themeList);

        $activeTheme = '';
        if (function_exists('wp_get_theme')) {
            $themeObj = wp_get_theme();
            if (is_object($themeObj) && method_exists($themeObj, 'get_stylesheet')) {
                $activeTheme = (string) $themeObj->get_stylesheet();
            }
        }

        return [
            'version' => '1.0',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'site' => [
                'domain' => $domain,
                'wp_version' => isset($wp_version) ? (string) $wp_version : 'unknown',
                'php_version' => PHP_VERSION,
            ],
            'summary' => [
                'total_files' => $entriesCount,
                'total_uncompressed_bytes' => (int) ($stats['total_uncompressed_bytes'] ?? 0),
                'archive_size_bytes' => 0,
                'compression_ratio' => '0%',
                'duration_seconds' => 0.0,
            ],
            'database' => [
                'tables_count' => (int) ($dbStats['tables_count'] ?? 0),
                'total_rows' => (int) ($dbStats['total_rows'] ?? 0),
                'raw_sql_bytes' => (int) ($dbStats['raw_bytes'] ?? 0),
                'compressed_sql_bytes' => (int) ($dbStats['size_bytes'] ?? 0),
                'tables' => is_array($dbStats['tables'] ?? null) ? $dbStats['tables'] : [],
            ],
            'files' => [
                'uploads' => [
                    'file_count' => (int) ($stats['uploads_count'] ?? 0),
                    'bytes' => (int) ($stats['uploads_bytes'] ?? 0),
                    'description' => 'Media library images, PDFs, videos, and uploaded documents',
                ],
                'plugins' => [
                    'count' => count($pluginList),
                    'file_count' => (int) ($stats['plugins_count'] ?? 0),
                    'bytes' => (int) ($stats['plugins_bytes'] ?? 0),
                    'list' => $pluginList,
                ],
                'themes' => [
                    'count' => count($themeList),
                    'file_count' => (int) ($stats['themes_count'] ?? 0),
                    'bytes' => (int) ($stats['themes_bytes'] ?? 0),
                    'active' => $activeTheme,
                    'list' => $themeList,
                ],
                'other' => [
                    'file_count' => (int) ($stats['other_count'] ?? 0),
                    'bytes' => (int) ($stats['other_bytes'] ?? 0),
                ],
                'config' => [
                    'wp_config' => ! empty($stats['has_wp_config']),
                    'htaccess' => ! empty($stats['has_htaccess']),
                ],
            ],
            'exclusions' => [
                'rules' => array_values($excludes),
                'skipped_directories_count' => (int) ($stats['skipped_dirs_count'] ?? 0),
            ],
        ];
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
                'error' => 'PclZip class could not be found.',
            ];
        }

        if (is_file($zipOutputPath)) {
            @unlink($zipOutputPath);
        }

        $pcl = new \PclZip($zipOutputPath);

        foreach ($entries as $entry) {
            if (! is_file($entry['source']) || ! is_readable($entry['source'])) {
                continue;
            }
            $destDir = dirname($entry['dest']);
            $addPath = $destDir === '.' ? '' : $destDir;
            $removePath = dirname($entry['source']);

            $result = $pcl->add($entry['source'], PCLZIP_OPT_ADD_PATH, $addPath, PCLZIP_OPT_REMOVE_PATH, $removePath);
            if ($result === 0) {
                return [
                    'ok' => false,
                    'size_bytes' => 0,
                    'sha256' => '',
                    'files_count' => 0,
                    'error' => 'PclZip error: '.$pcl->errorInfo(true),
                ];
            }
        }

        return null;
    }
}
