<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Backup\ArchiveDownloader;
use ClockworkCompanion\Backup\ArchiveExtractor;
use ClockworkCompanion\Backup\FileApplier;
use ClockworkCompanion\Backup\Paths;
use ClockworkCompanion\Backup\RestoreState;
use ClockworkCompanion\Backup\SqlImporter;
use ClockworkCompanion\Maintenance\MaintenanceGuard;
use ClockworkCompanion\Support\OperationLock;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Backup Restore Endpoints (Option A Direct Glacier Restore):
 *   POST /wp-json/clockwork/v1/backup/restore/stage
 *   GET  /wp-json/clockwork/v1/backup/restore/status
 *   POST /wp-json/clockwork/v1/backup/restore/apply
 */
class BackupRestoreRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/backup/restore/stage', [
            'methods' => 'POST',
            'callback' => [$this, 'handleStage'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);

        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/backup/restore/status', [
            'methods' => 'GET',
            'callback' => [$this, 'handleStatus'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);

        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/backup/restore/apply', [
            'methods' => 'POST',
            'callback' => [$this, 'handleApply'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handleStage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        ignore_user_abort(true);
        @set_time_limit(900);
        @ini_set('memory_limit', '512M');

        if (! OperationLock::acquire('backup_restore', 900)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'busy'], 429);
        }

        try {
            Paths::sweepStale(86400);

            $params = $request->get_json_params();
            if (! is_array($params)) {
                $params = $request->get_params();
            }

            $downloadUrl = trim((string) ($params['download_url'] ?? ''));
            if ($downloadUrl === '' || filter_var($downloadUrl, FILTER_VALIDATE_URL) === false || ! str_starts_with(strtolower($downloadUrl), 'https://')) {
                return new WP_Error('invalid_download_url', 'Valid HTTPS download_url is required.', ['status' => 400]);
            }

            $archiveKey = trim((string) ($params['archive_key'] ?? ''));
            if ($archiveKey === '') {
                return new WP_Error('missing_archive_key', 'archive_key parameter is required.', ['status' => 400]);
            }

            $expectedSha256 = trim((string) ($params['expected_sha256'] ?? ''));
            if ($expectedSha256 === '') {
                return new WP_Error('missing_expected_sha256', 'expected_sha256 parameter is required.', ['status' => 422]);
            }

            $expectedBytes = isset($params['expected_bytes']) ? (int) $params['expected_bytes'] : 0;
            $stagedId = bin2hex(random_bytes(8));

            $stagingBase = Paths::stagingDir();
            $archivePath = $stagingBase."/restore-archive-{$stagedId}.zip";
            $extractDir = $stagingBase."/restore-{$stagedId}";

            RestoreState::update([
                'staged_id' => $stagedId,
                'phase' => 'stage',
                'status' => 'downloading',
                'archive_key' => $archiveKey,
                'expected_sha256' => $expectedSha256,
                'actual_sha256' => null,
                'hash_verified' => false,
                'bytes_total' => $expectedBytes,
                'bytes_done' => 0,
                'has_sql' => false,
                'has_files' => false,
                'table_prefix' => null,
                'skipped_tables' => 0,
                'error' => null,
                'error_detail' => null,
            ]);

            // 1. Download archive
            $downloader = new ArchiveDownloader();
            $downloadResult = $downloader->download($downloadUrl, $archivePath, function (int $done, int $total) {
                RestoreState::update([
                    'bytes_done' => $done,
                    'bytes_total' => $total,
                ]);
            }, $archiveKey);

            if (! ($downloadResult['ok'] ?? false)) {
                if (file_exists($archivePath)) {
                    @unlink($archivePath);
                }
                RestoreState::update([
                    'status' => 'failed',
                    'error' => 'download_failed',
                    'error_detail' => $downloadResult['error'] ?? 'Download failed',
                ]);

                return new WP_Error('download_failed', $downloadResult['error'] ?? 'Archive download failed', ['status' => 502]);
            }

            // 2. Verify SHA-256
            RestoreState::update(['status' => 'verifying']);
            $actualSha256 = (string) hash_file('sha256', $archivePath);

            if (! hash_equals(strtolower($expectedSha256), strtolower($actualSha256))) {
                if (file_exists($archivePath)) {
                    @unlink($archivePath);
                }
                RestoreState::update([
                    'status' => 'failed',
                    'error' => 'hash_mismatch',
                    'actual_sha256' => $actualSha256,
                    'hash_verified' => false,
                    'error_detail' => "Hash mismatch: expected {$expectedSha256}, got {$actualSha256}",
                ]);

                return new WP_Error('hash_mismatch', "SHA-256 hash mismatch: expected {$expectedSha256}, got {$actualSha256}", ['status' => 422]);
            }

            RestoreState::update([
                'actual_sha256' => $actualSha256,
                'hash_verified' => true,
            ]);

            // 3. Extract archive
            RestoreState::update(['status' => 'extracting']);
            $extractor = new ArchiveExtractor();
            $extractResult = $extractor->extract($archivePath, $extractDir);

            if (! ($extractResult['ok'] ?? false)) {
                Paths::rmrf($extractDir);
                if (file_exists($archivePath)) {
                    @unlink($archivePath);
                }
                RestoreState::update([
                    'status' => 'failed',
                    'error' => 'extract_failed',
                    'error_detail' => $extractResult['error'] ?? 'Archive extraction failed',
                ]);

                return new WP_Error('extract_failed', $extractResult['error'] ?? 'Archive extraction failed', ['status' => 500]);
            }

            // 4. Pre-scan
            RestoreState::update(['status' => 'scanning']);
            $hasFiles = is_dir($extractDir.'/wp-content');
            $dbFiles = glob($extractDir.'/database/*.sql*') ?: [];
            $hasSql = ! empty($dbFiles);
            $tablePrefix = null;

            if ($hasSql) {
                $tablePrefix = $this->detectTablePrefix($dbFiles[0]);
            }

            $bytesFinal = (int) filesize($archivePath);
            RestoreState::update([
                'status' => 'staged',
                'has_sql' => $hasSql,
                'has_files' => $hasFiles,
                'table_prefix' => $tablePrefix,
                'bytes_done' => $bytesFinal,
            ]);

            return new WP_REST_Response([
                'ok' => true,
                'staged_id' => $stagedId,
                'bytes' => $bytesFinal,
                'sha256' => $actualSha256,
                'has_sql' => $hasSql,
                'has_files' => $hasFiles,
                'table_prefix' => $tablePrefix,
            ], 200);
        } finally {
            OperationLock::release('backup_restore');
        }
    }

    public function handleStatus(WP_REST_Request $request): WP_REST_Response
    {
        $state = RestoreState::get();

        if (($state['status'] ?? '') === 'staged' && ! empty($state['staged_id'])) {
            $stagingDir = Paths::stagingDir().'/restore-'.$state['staged_id'];
            if (! is_dir($stagingDir)) {
                $state['error_detail'] = 'staging_dir_missing';
            }
        }

        return new WP_REST_Response($state, 200);
    }

    public function handleApply(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        ignore_user_abort(true);
        @set_time_limit(900);
        @ini_set('memory_limit', '512M');

        if (! OperationLock::acquire('backup_restore', 900)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'busy'], 429);
        }

        try {
            $params = $request->get_json_params();
            if (! is_array($params)) {
                $params = $request->get_params();
            }

            $stagedId = trim((string) ($params['staged_id'] ?? ''));
            $state = RestoreState::get();

            if ($stagedId === '' || ($state['staged_id'] ?? '') !== $stagedId) {
                return new WP_Error('invalid_staged_id', 'Provided staged_id does not match active restore state.', ['status' => 409]);
            }

            if (($state['status'] ?? '') !== 'staged') {
                return new WP_Error('not_staged', 'Restore state is not ready to apply (current status: '.($state['status'] ?? 'unknown').').', ['status' => 409]);
            }

            if (empty($state['hash_verified'])) {
                return new WP_Error('hash_not_verified', 'Archive integrity hash was never verified.', ['status' => 422]);
            }

            $stagingBase = Paths::stagingDir();
            $extractDir = $stagingBase."/restore-{$stagedId}";
            $archivePath = $stagingBase."/restore-archive-{$stagedId}.zip";

            // 1. Enter Maintenance Mode (D7)
            $maint = MaintenanceGuard::getConfig();
            $maint['enabled'] = true;
            update_option(MaintenanceGuard::OPTION_KEY, $maint);

            // 2. Import SQL (D5)
            RestoreState::update([
                'phase' => 'apply',
                'status' => 'applying_sql',
            ]);

            if (! empty($state['has_sql'])) {
                $dbFiles = glob($extractDir.'/database/*.sql*') ?: [];
                if (! empty($dbFiles)) {
                    $importer = new SqlImporter();
                    $importResult = $importer->import($dbFiles[0]);

                    if (! ($importResult['ok'] ?? false)) {
                        RestoreState::update([
                            'status' => 'failed',
                            'error' => $importResult['error'] ?? 'sql_failed',
                            'error_detail' => $importResult['detail'] ?? 'Database import failed',
                            'skipped_tables' => $importResult['skipped_tables'] ?? 0,
                        ]);

                        // Fail-closed: Maintenance mode stays ENABLED!
                        return new WP_Error($importResult['error'] ?? 'sql_failed', $importResult['detail'] ?? 'Database import failed', ['status' => 500]);
                    }

                    RestoreState::update(['skipped_tables' => $importResult['skipped_tables'] ?? 0]);
                }
            }

            // 3. Copy files (D6)
            RestoreState::update(['status' => 'applying_files']);

            if (! empty($state['has_files'])) {
                $applier = new FileApplier();
                $applyResult = $applier->apply($extractDir.'/wp-content');

                if (! ($applyResult['ok'] ?? false)) {
                    RestoreState::update([
                        'status' => 'failed',
                        'error' => 'files_failed',
                        'error_detail' => $applyResult['error'] ?? 'File copy failed',
                    ]);

                    // Fail-closed: Maintenance mode stays ENABLED!
                    return new WP_Error('files_failed', $applyResult['error'] ?? 'File copy failed', ['status' => 500]);
                }
            }

            // 4. Finalize
            RestoreState::update(['status' => 'finalizing']);

            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            if (function_exists('spinupwp_purge_site')) {
                spinupwp_purge_site();
            }
            if (class_exists('WpeCommon') && method_exists('WpeCommon', 'purge_varnish_cache')) {
                \WpeCommon::purge_varnish_cache();
            }
            if (function_exists('do_action')) {
                do_action('clockwork_companion_cache_flush');
            }

            // 5. Exit Maintenance Mode on full success
            $maint = MaintenanceGuard::getConfig();
            $maint['enabled'] = false;
            update_option(MaintenanceGuard::OPTION_KEY, $maint);

            // Cleanup staging files
            Paths::rmrf($extractDir);
            if (file_exists($archivePath)) {
                @unlink($archivePath);
            }

            RestoreState::update(['status' => 'applied']);

            return new WP_REST_Response([
                'ok' => true,
                'status' => 'applied',
                'staged_id' => $stagedId,
            ], 200);
        } finally {
            OperationLock::release('backup_restore');
        }
    }

    private function detectTablePrefix(string $sqlFilePath): ?string
    {
        $isGz = str_ends_with($sqlFilePath, '.gz') && function_exists('gzopen');
        $handle = $isGz ? @gzopen($sqlFilePath, 'rb') : @fopen($sqlFilePath, 'rb');

        if (! $handle) {
            return null;
        }

        $detected = null;

        while (! ($isGz ? gzeof($handle) : feof($handle))) {
            $line = $isGz ? gzgets($handle, 4096) : fgets($handle, 4096);
            if ($line === false) {
                break;
            }

            if (preg_match('/^(?:DROP TABLE(?:\s+IF EXISTS)?|CREATE TABLE(?:\s+IF NOT EXISTS)?)\s+[`"]?([^`"\s]+)[`"]?/i', trim($line), $matches)) {
                $table = $matches[1];
                if (str_ends_with($table, 'options')) {
                    $detected = substr($table, 0, -7);
                    break;
                } elseif (str_ends_with($table, 'posts')) {
                    $detected = substr($table, 0, -5);
                    break;
                } elseif (str_ends_with($table, 'users')) {
                    $detected = substr($table, 0, -5);
                    break;
                } elseif ($detected === null) {
                    $detected = strtok($table, '_').'_';
                }
            }
        }

        if ($isGz) {
            gzclose($handle);
        } else {
            fclose($handle);
        }

        return $detected;
    }
}
