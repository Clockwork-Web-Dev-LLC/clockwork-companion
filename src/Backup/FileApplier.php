<?php

namespace ClockworkCompanion\Backup;

/**
 * Copies staging wp-content files over the live WP_CONTENT_DIR.
 * Additive/overwrite: does not delete newer files created after the backup.
 * Excludes cache, staging, upgrade/, debug.log.
 * Copies Companion files last.
 */
class FileApplier
{
    /** @var callable|null Test seam: fn(string $stagingWpContent, string $liveWpContent): array{ok: bool, copied_count: int, error?: string} */
    public static $testApplier = null;

    /**
     * Copy staging wp-content files over live WP_CONTENT_DIR.
     *
     * @param  string  $stagingContentDir Extracted staging wp-content directory.
     * @param  string|null  $liveContentDir Live WP_CONTENT_DIR.
     * @return array{ok: bool, copied_count: int, error?: string}
     */
    public function apply(string $stagingContentDir, ?string $liveContentDir = null): array
    {
        if (! is_dir($stagingContentDir)) {
            return ['ok' => true, 'copied_count' => 0];
        }

        if ($liveContentDir === null || $liveContentDir === '') {
            $liveContentDir = defined('WP_CONTENT_DIR') ? constant('WP_CONTENT_DIR') : (defined('ABSPATH') ? ABSPATH.'wp-content' : '');
        }

        if ($liveContentDir === '') {
            return ['ok' => false, 'copied_count' => 0, 'error' => 'Live WP_CONTENT_DIR is not defined.'];
        }

        if (! is_dir($liveContentDir) && ! @mkdir($liveContentDir, 0755, true) && ! is_dir($liveContentDir)) {
            return ['ok' => false, 'copied_count' => 0, 'error' => 'Live WP_CONTENT_DIR is invalid or cannot be created.'];
        }

        if (is_callable(self::$testApplier)) {
            $res = call_user_func(self::$testApplier, $stagingContentDir, $liveContentDir);
            if (is_array($res)) {
                return $res;
            }

            return ['ok' => (bool) $res, 'copied_count' => 1];
        }

        $companionDir = defined('CLOCKWORK_COMPANION_DIR') ? realpath(constant('CLOCKWORK_COMPANION_DIR')) : '';

        $excludeNames = array_merge(BackupArchiver::DEFAULT_EXCLUDES, [
            'upgrade',
            'debug.log',
            'index.php',
        ]);

        $deferredCompanionFiles = [];
        $copiedCount = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingContentDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $subPath = substr($item->getPathname(), strlen(rtrim($stagingContentDir, '/\\')) + 1);
            $normalizedSubPath = str_replace('\\', '/', $subPath);
            $parts = explode('/', $normalizedSubPath);

            $skip = false;
            foreach ($parts as $p) {
                if (in_array(strtolower($p), $excludeNames, true) || str_starts_with($p, 'clockwork-backups')) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            $dest = rtrim($liveContentDir, '/\\').DIRECTORY_SEPARATOR.$subPath;

            if ($item->isDir()) {
                if (! is_dir($dest) && ! @mkdir($dest, 0755, true) && ! is_dir($dest)) {
                    return ['ok' => false, 'copied_count' => $copiedCount, 'error' => "Failed to create directory {$dest}"];
                }
                continue;
            }

            if ($companionDir !== '' && str_starts_with(realpath(dirname($dest)) ?: $dest, $companionDir)) {
                $deferredCompanionFiles[] = [$item->getPathname(), $dest];
                continue;
            }

            if (! @copy($item->getPathname(), $dest)) {
                return ['ok' => false, 'copied_count' => $copiedCount, 'error' => "Failed to copy file to {$dest}"];
            }
            $copiedCount++;
        }

        foreach ($deferredCompanionFiles as [$srcFile, $destFile]) {
            if (! @copy($srcFile, $destFile)) {
                return ['ok' => false, 'copied_count' => $copiedCount, 'error' => "Failed to copy companion file to {$destFile}"];
            }
            $copiedCount++;
        }

        return ['ok' => true, 'copied_count' => $copiedCount];
    }
}
