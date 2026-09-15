<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for remote debug log inspection and truncation.
 *
 * GET    /wp-json/clockwork/v1/debug-log
 * DELETE /wp-json/clockwork/v1/debug-log
 */
class DebugLogRoute
{
    private const MAX_TAIL_BYTES = 524288; // 512 KiB

    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/debug-log', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'handleGet'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'handleClear'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
        ]);
    }

    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        $linesRequested = max(1, min(2000, (int) ($request->get_param('lines') ?: 100)));
        $logPath = $this->resolveLogPath();
        $isEnabled = (defined('WP_DEBUG') && WP_DEBUG) && (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG);

        if ($logPath === null) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Debug log path is not allowed.',
            ], 403);
        }

        if (! file_exists($logPath)) {
            return new WP_REST_Response([
                'ok'          => true,
                'exists'      => false,
                'enabled'     => $isEnabled,
                'file_size'   => 0,
                'line_count'  => 0,
                'lines'       => [],
                'path_masked' => 'wp-content/debug.log',
            ]);
        }

        $fileSize = (int) @filesize($logPath);
        $lines = $this->tailFile($logPath, $linesRequested);
        $sanitized = array_map([$this, 'maskSensitiveData'], $lines);

        return new WP_REST_Response([
            'ok'          => true,
            'exists'      => true,
            'enabled'     => $isEnabled,
            'file_size'   => $fileSize,
            'line_count'  => count($sanitized),
            'lines'       => $sanitized,
            'path_masked' => 'wp-content/debug.log',
        ]);
    }

    public function handleClear(WP_REST_Request $request): WP_REST_Response
    {
        $logPath = $this->resolveLogPath();

        if ($logPath === null) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Debug log path is not allowed.',
            ], 403);
        }

        if (! file_exists($logPath)) {
            return new WP_REST_Response([
                'ok'      => true,
                'cleared' => true,
                'message' => 'Log file did not exist.',
            ]);
        }

        if (! is_writable($logPath)) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Log file is not writable.',
            ], 403);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $cleared = @file_put_contents($logPath, '') !== false;

        return new WP_REST_Response([
            'ok'      => $cleared,
            'cleared' => $cleared,
        ]);
    }

    /**
     * Authorize a candidate filesystem path as the debug log.
     * Rejects paths outside WP_CONTENT_DIR and sensitive filenames.
     *
     * @internal Used by tests.
     */
    public function authorizeLogPath(string $candidate): ?string
    {
        $candidate = str_replace('\\', '/', $candidate);
        if ($candidate === '') {
            return null;
        }

        if (! $this->isAbsolutePath($candidate)) {
            $candidate = rtrim(str_replace('\\', '/', ABSPATH), '/') . '/' . ltrim($candidate, '/');
        }

        $resolved = $this->canonicalizePath($candidate);
        if ($resolved === null || ! $this->isAllowedLogPath($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Resolves the debug log path, or null if the configured path is unsafe.
     */
    private function resolveLogPath(): ?string
    {
        $candidate = WP_CONTENT_DIR . '/debug.log';
        if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
            $candidate = WP_DEBUG_LOG;
        }

        return $this->authorizeLogPath($candidate);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:\//', $path);
    }

    private function canonicalizePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $forbidden = ['wp-config.php', 'wp-config-sample.php', '.htaccess', '.env', 'wp-config.php.bak'];
        if (in_array(strtolower(basename($path)), $forbidden, true)) {
            return null;
        }

        if (is_file($path) || is_link($path)) {
            $real = realpath($path);
            return $real !== false ? $real : null;
        }

        $dir = dirname($path);
        $base = basename($path);
        if (is_dir($dir)) {
            $realDir = realpath($dir);
            if ($realDir === false) {
                return null;
            }

            return $realDir . DIRECTORY_SEPARATOR . $base;
        }

        return null;
    }

    private function isAllowedLogPath(string $path): bool
    {
        $content = realpath(WP_CONTENT_DIR);
        if ($content === false) {
            return false;
        }

        $normalized = $this->normalizePath($path);
        $contentNorm = $this->normalizePath($content);

        return $normalized === $contentNorm
            || str_starts_with($normalized, $contentNorm . '/');
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return rtrim($path, '/');
    }

    /**
     * Efficiently tails the last N lines using reverse seek chunk reading.
     * Memory is bounded by MAX_TAIL_BYTES even on newline-sparse files.
     *
     * @return array<int, string>
     */
    private function tailFile(string $filePath, int $lines): array
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = @fopen($filePath, 'rb');
        if (! $handle) {
            return [];
        }

        $chunkSize = 8192;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek
        fseek($handle, 0, SEEK_END);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell
        $pos = ftell($handle);

        $buffer = '';
        $lineCount = 0;

        while ($pos > 0 && $lineCount <= $lines && strlen($buffer) < self::MAX_TAIL_BYTES) {
            $readSize = min($chunkSize, $pos);
            $remainingBudget = self::MAX_TAIL_BYTES - strlen($buffer);
            $readSize = min($readSize, $remainingBudget);
            if ($readSize <= 0) {
                break;
            }
            $pos -= $readSize;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek
            fseek($handle, $pos);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        $allLines = explode("\n", rtrim($buffer, "\n"));
        if (count($allLines) > $lines) {
            $allLines = array_slice($allLines, -$lines);
        }

        return array_values(array_filter($allLines, static function ($line) {
            return $line !== '';
        }));
    }

    /**
     * Masks sensitive filepaths and credentials in log lines.
     */
    private function maskSensitiveData(string $line): string
    {
        if (defined('ABSPATH')) {
            $line = str_replace(ABSPATH, '/ABSPATH/', $line);
        }
        if (defined('WP_CONTENT_DIR')) {
            $line = str_replace(WP_CONTENT_DIR, '/wp-content', $line);
        }

        $line = (string) preg_replace('~/(?:home|Users)/[a-zA-Z0-9._-]+/~', '/[HOME]/', $line);
        $line = (string) preg_replace('~/(?:var/www|srv|opt/bitnami)/[^\s:]+~', '/[WEBROOT]/', $line);

        $line = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.]{10,}/i', '$1[REDACTED]', $line);
        $line = (string) preg_replace('/(Authorization:\s*Basic\s+)[A-Za-z0-9+\/=]+/i', '$1[REDACTED]', $line);
        $line = (string) preg_replace('/(x-clockwork-signature:\s*)[A-Fa-f0-9]{16,}/i', '$1[REDACTED]', $line);
        $line = (string) preg_replace('/\bAKIA[0-9A-Z]{16}\b/', '[REDACTED_AWS_KEY]', $line);
        $line = (string) preg_replace('/\beyJ[A-Za-z0-9_\-]+=*\.[A-Za-z0-9_\-]+=*\.[A-Za-z0-9_\-]+=*/', '[REDACTED_JWT]', $line);
        $line = (string) preg_replace('/(password|passwd|pwd|token|secret|DB_PASSWORD|MYSQL_PWD)[\s=:\x22\x27]+([^\s\x22\x27;,&]{4,})/i', '$1=[REDACTED]', $line);

        return $line;
    }
}
