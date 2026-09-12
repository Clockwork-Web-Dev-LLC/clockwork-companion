<?php

namespace ClockworkCompanion\Backup;

class Paths
{
    /**
     * Return the base staging directory for backup archives and restore unpacks,
     * ensuring it exists and is protected against direct web access.
     */
    public static function stagingDir(): string
    {
        $uploadDirInfo = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => sys_get_temp_dir()];
        $dir = rtrim((string) ($uploadDirInfo['basedir'] ?? sys_get_temp_dir()), '/') . '/clockwork-backups';

        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return $dir;
            }
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }

        return $dir;
    }

    /**
     * Recursively delete a directory and its contents.
     */
    public static function rmrf(string $dir): bool
    {
        if (! is_dir($dir)) {
            return ! file_exists($dir) || @unlink($dir);
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
            if (is_dir($target)) {
                self::rmrf($target);
            } else {
                @unlink($target);
            }
        }

        return @rmdir($dir);
    }

    /**
     * Sweep stale temporary files and restore directories older than $olderThanSeconds.
     * Matches patterns: db-*, backup-*, restore-*.
     *
     * @return int Number of stale items removed.
     */
    public static function sweepStale(int $olderThanSeconds = 86400): int
    {
        $dir = self::stagingDir();
        if (! is_dir($dir)) {
            return 0;
        }

        $now = time();
        $cutoff = $now - max(1, $olderThanSeconds);
        $removed = 0;

        $items = @scandir($dir);
        if ($items === false) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'index.php' || $item === '.htaccess') {
                continue;
            }

            if (! preg_match('/^(db-|backup-|restore-)/', $item)) {
                continue;
            }

            $path = $dir . '/' . $item;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                if (is_dir($path)) {
                    if (self::rmrf($path)) {
                        $removed++;
                    }
                } else {
                    if (@unlink($path)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }
}
