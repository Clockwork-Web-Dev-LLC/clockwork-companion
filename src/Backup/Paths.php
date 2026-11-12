<?php

namespace ClockworkCompanion\Backup;

/**
 * Where backup and restore work files live, and how they are cleaned up.
 *
 * These files hold a full database dump and wp-config.php, so:
 * - they go outside the web root when a writable place is available (see
 *   stagingDir()); otherwise into uploads/clockwork-backups, protected by
 *   deny rules that Apache honours and nginx does not;
 * - every name in there carries a random token, so a file left in a public
 *   directory cannot be fetched by computing its name;
 * - leftovers are swept on every backup and restore call, and each run
 *   removes its own files on shutdown if it dies (fatal error, timeout).
 */
class Paths
{
    /** Seconds after which an unfinished backup's temp files are removed. */
    public const BACKUP_TEMP_TTL = 3600;

    /** Seconds a staged (downloaded, extracted, not yet applied) restore and a resumable partial download are kept. */
    public const RESTORE_TTL = 86400;

    /** @var list<string> */
    private static array $shutdownPaths = [];

    private static bool $shutdownRegistered = false;

    /**
     * Return the base staging directory for backup archives and restore
     * unpacks, ensuring it exists.
     *
     * Order: the CLOCKWORK_BACKUP_DIR constant; a directory beside the
     * WordPress root when that root's parent is outside the document root
     * and writable; uploads/clockwork-backups. The system temp directory is
     * deliberately not used: on clustered hosts the stage and apply requests
     * can land on different servers, and only the site's own files are
     * shared between them.
     */
    public static function stagingDir(): string
    {
        $dir = null;

        if (defined('CLOCKWORK_BACKUP_DIR') && is_string(constant('CLOCKWORK_BACKUP_DIR')) && constant('CLOCKWORK_BACKUP_DIR') !== '') {
            $dir = rtrim((string) constant('CLOCKWORK_BACKUP_DIR'), '/\\');
        } else {
            $dir = self::outsideDocumentRoot();
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('clockwork_backup_staging_dir', $dir);
            $dir = is_string($filtered) && $filtered !== '' ? rtrim($filtered, '/\\') : $dir;
        }

        if ($dir === null || ! self::ensureDir($dir)) {
            $uploadDirInfo = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => sys_get_temp_dir()];
            $dir = rtrim((string) ($uploadDirInfo['basedir'] ?? sys_get_temp_dir()), '/').'/clockwork-backups';
            self::ensureDir($dir);
        }

        self::protect($dir);

        return $dir;
    }

    /**
     * A per-site directory next to the WordPress root, when that is outside
     * the document root. Null when it is not, when the document root is
     * unknown, or when it cannot be created.
     */
    private static function outsideDocumentRoot(): ?string
    {
        if (! defined('ABSPATH')) {
            return null;
        }

        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        $wpRoot = realpath(ABSPATH);
        if ($docRoot === false || $docRoot === '' || $wpRoot === false) {
            return null;
        }

        $parent = dirname($wpRoot);
        if ($parent === $wpRoot || str_starts_with($parent.'/', rtrim($docRoot, '/').'/')) {
            return null;
        }

        // Several sites can share the parent directory; keep them apart.
        $dir = $parent.'/clockwork-backups-'.substr(hash('sha256', $wpRoot), 0, 12);
        if (is_dir($dir)) {
            return is_writable($dir) ? $dir : null;
        }

        return @is_writable($parent) ? $dir : null;
    }

    private static function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        return (@mkdir($dir, 0700, true) || is_dir($dir)) && is_writable($dir);
    }

    /**
     * Deny rules and an index stub, re-created if something removed them.
     * They are a second line of defence: nginx ignores .htaccess, which is
     * why names in here are random as well.
     */
    private static function protect(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = [
            'index.php' => "<?php\n// Silence is golden.\n",
            'index.html' => '',
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];

        foreach ($files as $name => $content) {
            if (! file_exists($dir.'/'.$name)) {
                @file_put_contents($dir.'/'.$name, $content);
            }
        }
    }

    /**
     * A random token for file names: 128 bits, hex.
     */
    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Delete $paths (files or directories) when the request ends, unless
     * forgetOnShutdown() is called for them first. Covers fatal errors and
     * timeouts, which skip finally blocks.
     *
     * @param  list<string>  $paths
     */
    public static function removeOnShutdown(array $paths): void
    {
        foreach ($paths as $path) {
            self::$shutdownPaths[] = $path;
        }

        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'runShutdownCleanup']);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public static function forgetOnShutdown(array $paths): void
    {
        self::$shutdownPaths = array_values(array_diff(self::$shutdownPaths, $paths));
    }

    /**
     * @internal Shutdown callback; public so register_shutdown_function and tests can call it.
     */
    public static function runShutdownCleanup(): void
    {
        foreach (self::$shutdownPaths as $path) {
            self::rmrf($path);
        }
        self::$shutdownPaths = [];
    }

    /**
     * Recursively delete a directory and its contents.
     */
    public static function rmrf(string $dir): bool
    {
        if (! is_dir($dir) || is_link($dir)) {
            return ! file_exists($dir) && ! is_link($dir) || @unlink($dir);
        }

        $files = @scandir($dir);
        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $target = $dir . '/' . $file;
            if (is_dir($target) && ! is_link($target)) {
                self::rmrf($target);
            } else {
                @unlink($target);
            }
        }

        return @rmdir($dir);
    }

    /**
     * Sweep leftover work files: unfinished backup temp files (db-*, backup-*,
     * manifest-*) older than BACKUP_TEMP_TTL, and restore downloads, staged
     * extracts and partial downloads (restore-*) older than RESTORE_TTL.
     * Pass $olderThanSeconds to use one age for everything (0 = all).
     *
     * @param  list<string>  $keep Names in the staging directory to leave alone.
     * @return int Number of stale items removed.
     */
    public static function sweepStale(?int $olderThanSeconds = null, array $keep = []): int
    {
        $dir = self::stagingDir();
        if (! is_dir($dir)) {
            return 0;
        }

        $now = time();
        $removed = 0;

        $items = @scandir($dir);
        if ($items === false) {
            return 0;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..', 'index.php', 'index.html', '.htaccess', 'web.config'], true) || in_array($item, $keep, true)) {
                continue;
            }

            if (preg_match('/^(db-|backup-|manifest-)/', $item)) {
                $ttl = $olderThanSeconds ?? self::BACKUP_TEMP_TTL;
            } elseif (str_starts_with($item, 'restore-')) {
                $ttl = $olderThanSeconds ?? self::RESTORE_TTL;
            } else {
                continue;
            }

            $path = $dir . '/' . $item;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime <= $now - max(0, $ttl)) {
                if (self::rmrf($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
