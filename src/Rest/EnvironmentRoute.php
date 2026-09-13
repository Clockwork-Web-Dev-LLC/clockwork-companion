<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for server, PHP, WordPress, and database environment telemetry.
 *
 * GET /wp-json/clockwork-renegade/v1/environment
 */
class EnvironmentRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/environment', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->payload());
    }

    /**
     * Full environment telemetry.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        global $wpdb, $wp_version;

        $dbInfo = $this->databaseTelemetry();
        $uploadDir = wp_upload_dir();
        $uploadBasedir = isset($uploadDir['basedir']) ? (string) $uploadDir['basedir'] : '';
        $serverSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['SERVER_SOFTWARE'])) : '';

        return [
            'ok'           => true,
            'captured_at'  => gmdate('c'),
            'php'          => [
                'version'             => PHP_VERSION,
                'memory_limit'        => (string) ini_get('memory_limit'),
                'max_execution_time'  => (int) ini_get('max_execution_time'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size'       => (string) ini_get('post_max_size'),
                'display_errors'      => (bool) ini_get('display_errors'),
                'opcache_enabled'     => function_exists('opcache_get_status') && ! empty(opcache_get_status(false)['opcache_enabled']),
                'curl_version'        => function_exists('curl_version') ? ((array) curl_version())['version'] ?? null : null,
                'critical_extensions' => [
                    'curl'      => extension_loaded('curl'),
                    'mbstring'  => extension_loaded('mbstring'),
                    'openssl'   => extension_loaded('openssl'),
                    'zip'       => extension_loaded('zip'),
                    'gd'        => extension_loaded('gd'),
                    'imagick'   => extension_loaded('imagick'),
                    'redis'     => extension_loaded('redis'),
                    'memcached' => extension_loaded('memcached'),
                ],
            ],
            'database'     => $dbInfo,
            'wordpress'    => [
                'version'          => (string) $wp_version,
                'multisite'        => is_multisite(),
                'home_url'         => get_home_url(),
                'site_url'         => get_site_url(),
                'is_ssl'           => is_ssl(),
                'debug'            => defined('WP_DEBUG') && WP_DEBUG,
                'debug_log'        => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
                'debug_display'    => defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
                'script_debug'     => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG,
                'cron_disabled'    => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'alternate_cron'   => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
                'permalink_structure' => (string) get_option('permalink_structure', ''),
            ],
            'server'       => [
                'software'     => $serverSoftware,
                'web_server'   => $this->detectWebServer($serverSoftware),
                'os'           => PHP_OS,
                'architecture' => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
            ],
            'storage'      => [
                'uploads_writable' => ! empty($uploadBasedir) && is_writable($uploadBasedir),
            ],
            'object_cache' => $this->objectCacheTelemetry(),
        ];
    }

    /**
     * Concise summary embedded into SnapshotRoute.
     *
     * @return array<string, mixed>
     */
    public function summaryPayload(): array
    {
        global $wpdb;

        $dbInfo = $this->databaseTelemetry();
        $serverSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['SERVER_SOFTWARE'])) : '';
        $objCache = $this->objectCacheTelemetry();

        return [
            'php_version'      => PHP_VERSION,
            'memory_limit'     => (string) ini_get('memory_limit'),
            'db_version'       => (string) ($dbInfo['server_version'] ?? ''),
            'db_size_bytes'    => (int) ($dbInfo['size_bytes'] ?? 0),
            'object_cache'     => (bool) ($objCache['dropin_present'] ?? false),
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'web_server'       => $this->detectWebServer($serverSoftware),
        ];
    }

    /**
     * Database telemetry.
     *
     * @return array<string, mixed>
     */
    private function databaseTelemetry(): array
    {
        global $wpdb;

        $serverVersion = method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : '';
        $serverInfo = method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $tablesStatus = $wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->prefix}%'", ARRAY_A);

        $sizeBytes = 0;
        $overheadBytes = 0;
        $tableCount = 0;

        if (is_array($tablesStatus)) {
            $tableCount = count($tablesStatus);
            foreach ($tablesStatus as $row) {
                $dataLength = (int) ($row['Data_length'] ?? 0);
                $indexLength = (int) ($row['Index_length'] ?? 0);
                $dataFree = (int) ($row['Data_free'] ?? 0);

                $sizeBytes += ($dataLength + $indexLength);
                $overheadBytes += $dataFree;
            }
        }

        return [
            'server_version' => $serverVersion,
            'server_info'    => $serverInfo,
            'prefix'         => (string) $wpdb->prefix,
            'table_count'    => $tableCount,
            'size_bytes'     => $sizeBytes,
            'overhead_bytes' => $overheadBytes,
        ];
    }

    /**
     * Object cache telemetry.
     *
     * @return array{dropin_present: bool, engine: string}
     */
    private function objectCacheTelemetry(): array
    {
        $dropinPath = WP_CONTENT_DIR . '/object-cache.php';
        $present = file_exists($dropinPath);
        $engine = 'none';

        if ($present) {
            // Check known object cache drop-in signatures
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
            $content = @file_get_contents($dropinPath);
            if (is_string($content)) {
                if (stripos($content, 'redis') !== false) {
                    $engine = 'redis';
                } elseif (stripos($content, 'memcached') !== false || stripos($content, 'memcache') !== false) {
                    $engine = 'memcached';
                } elseif (stripos($content, 'apcu') !== false) {
                    $engine = 'apcu';
                } else {
                    $engine = 'custom';
                }
            } else {
                $engine = 'unknown';
            }
        }

        return [
            'dropin_present' => $present,
            'engine'         => $engine,
        ];
    }

    private function detectWebServer(string $software): string
    {
        $software = strtolower($software);
        if (str_contains($software, 'nginx')) {
            return 'nginx';
        }
        if (str_contains($software, 'litespeed') || str_contains($software, 'openlitespeed')) {
            return 'litespeed';
        }
        if (str_contains($software, 'apache')) {
            return 'apache';
        }
        if (str_contains($software, 'caddy')) {
            return 'caddy';
        }
        if (str_contains($software, 'microsoft-iis')) {
            return 'iis';
        }

        return 'unknown';
    }
}
