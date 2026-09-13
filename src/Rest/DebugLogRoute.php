<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for remote debug log inspection and truncation.
 *
 * GET    /wp-json/clockwork-renegade/v1/debug-log
 * DELETE /wp-json/clockwork-renegade/v1/debug-log
 */
class DebugLogRoute
{
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
     * Resolves the debug log path.
     */
    private function resolveLogPath(): string
    {
        if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
            return WP_DEBUG_LOG;
        }

        return WP_CONTENT_DIR . '/debug.log';
    }

    /**
     * Efficiently tails the last N lines using reverse seek chunk reading.
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

        while ($pos > 0 && $lineCount <= $lines) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek
            fseek($handle, $pos);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "
");
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        $allLines = explode("
", rtrim($buffer, "
"));
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
        // Mask full filesystem paths
        if (defined('ABSPATH')) {
            $line = str_replace(ABSPATH, '/ABSPATH/', $line);
        }
        if (defined('WP_CONTENT_DIR')) {
            $line = str_replace(WP_CONTENT_DIR, '/wp-content', $line);
        }

        // Mask home directory paths e.g. /home/username/
        $line = (string) preg_replace('~/(?:home|Users)/[a-zA-Z0-9_-]+/~', '/[HOME]/', $line);

        // Mask Bearer tokens
        $line = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.]{10,}/i', '$1[REDACTED]', $line);

        // Mask common credential query parameters or key-value pairs
        $line = (string) preg_replace('/(password|passwd|pwd|token|secret)[\s=:\x22\x27]+([^\s\x22\x27;,&]{4,})/i', '$1=[REDACTED]', $line);

        return $line;
    }
}
