<?php

namespace ClockworkCompanion\Backup;

/**
 * Copies staging wp-content files over the live WP_CONTENT_DIR.
 * Additive/overwrite: does not delete newer files created after the backup.
 * Excludes cache, staging, upgrade/, debug.log.
 * Never copies a wp-config.php from the archive.
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
     * @param  string|null  $companionDir Companion plugin directory (defaults to CLOCKWORK_COMPANION_DIR); its files are copied last.
     * @return array{ok: bool, copied_count: int, deferred_companion_count: int, error?: string}
     */
    public function apply(string $stagingContentDir, ?string $liveContentDir = null, ?string $companionDir = null): array
    {
        if (! is_dir($stagingContentDir)) {
            return ['ok' => true, 'copied_count' => 0, 'deferred_companion_count' => 0];
        }

        if ($liveContentDir === null || $liveContentDir === '') {
            $liveContentDir = defined('WP_CONTENT_DIR') ? constant('WP_CONTENT_DIR') : (defined('ABSPATH') ? ABSPATH.'wp-content' : '');
        }

        if ($liveContentDir === '') {
            return ['ok' => false, 'copied_count' => 0, 'deferred_companion_count' => 0, 'error' => 'Live WP_CONTENT_DIR is not defined.'];
        }

        if (! is_dir($liveContentDir) && ! @mkdir($liveContentDir, 0755, true) && ! is_dir($liveContentDir)) {
            return ['ok' => false, 'copied_count' => 0, 'deferred_companion_count' => 0, 'error' => 'Live WP_CONTENT_DIR is invalid or cannot be created.'];
        }

        if (is_callable(self::$testApplier)) {
            $res = call_user_func(self::$testApplier, $stagingContentDir, $liveContentDir);
            if (is_array($res)) {
                return $res;
            }

            return ['ok' => (bool) $res, 'copied_count' => 1, 'deferred_companion_count' => 0];
        }

        if ($companionDir === null) {
            $companionDir = defined('CLOCKWORK_COMPANION_DIR') ? (string) constant('CLOCKWORK_COMPANION_DIR') : '';
        }
        if ($companionDir !== '') {
            $resolved = realpath($companionDir);
            $companionDir = $resolved !== false ? $resolved : rtrim($companionDir, '/\\');
        }

        // Note: 'index.php' is deliberately NOT excluded — theme/plugin index.php files
        // must be restored. The staging marker lives under 'clockwork-backups', which the
        // wholesale directory exclusion below already protects.
        $excludeNames = array_merge(BackupArchiver::DEFAULT_EXCLUDES, [
            'upgrade',
            'debug.log',
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

            // Defense-in-depth: never restore a wp-config.php shipped inside the archive.
            if (! $item->isDir() && strtolower($item->getFilename()) === 'wp-config.php') {
                continue;
            }

            $dest = rtrim($liveContentDir, '/\\').DIRECTORY_SEPARATOR.$subPath;

            if ($item->isDir()) {
                if (! is_dir($dest) && ! @mkdir($dest, 0755, true) && ! is_dir($dest)) {
                    return ['ok' => false, 'copied_count' => $copiedCount, 'deferred_companion_count' => count($deferredCompanionFiles), 'error' => "Failed to create directory {$dest}"];
                }
                continue;
            }

            if ($companionDir !== '' && $this->isInsideCompanionDir($dest, $companionDir)) {
                $deferredCompanionFiles[] = [$item->getPathname(), $dest];
                continue;
            }

            if (! @copy($item->getPathname(), $dest)) {
                return ['ok' => false, 'copied_count' => $copiedCount, 'deferred_companion_count' => count($deferredCompanionFiles), 'error' => "Failed to copy file to {$dest}"];
            }
            $copiedCount++;
        }

        foreach ($deferredCompanionFiles as $pair) {
            $srcFile = $pair[0];
            $destFile = $pair[1];
            if (! @copy($srcFile, $destFile)) {
                return ['ok' => false, 'copied_count' => $copiedCount, 'deferred_companion_count' => count($deferredCompanionFiles), 'error' => "Failed to copy companion file to {$destFile}"];
            }
            $copiedCount++;
        }

        return ['ok' => true, 'copied_count' => $copiedCount, 'deferred_companion_count' => count($deferredCompanionFiles)];
    }

    /**
     * True when the destination file lives inside the Companion plugin directory.
     * Compares against the companion dir with a trailing separator so a sibling
     * like "clockwork-companion-backup/" is never treated as the Companion dir.
     */
    private function isInsideCompanionDir(string $dest, string $companionDir): bool
    {
        $parent = dirname($dest);
        $resolvedParent = realpath($parent);
        if ($resolvedParent !== false) {
            $parent = $resolvedParent;
        }

        return $parent === $companionDir
            || str_starts_with($parent, $companionDir.DIRECTORY_SEPARATOR);
    }
}
