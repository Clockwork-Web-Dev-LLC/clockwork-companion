<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Backup\BackupArchiver;
use ClockworkCompanion\Backup\Paths;
use ClockworkCompanion\Backup\DatabaseDumper;
use ClockworkCompanion\Backup\S3DirectUploader;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/backup/create
 *
 * Direct-to-S3 Glacier IR Backup (Option A):
 *   Receives a presigned S3 PUT URL and optional headers from Clockwork Control.
 *   Dumps database, compresses wp-content/, streams the archive directly to AWS S3,
 *   purges local staging files immediately, and returns archive size + sha256.
 */
class BackupCreateRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/backup/create', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startTime = microtime(true);

        @set_time_limit(900);
        @ini_set('memory_limit', '512M');

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = $request->get_params();
        }

        $uploadUrl = trim((string) ($params['upload_url'] ?? ''));
        if ($uploadUrl === '' || filter_var($uploadUrl, FILTER_VALIDATE_URL) === false) {
            return new WP_Error('invalid_upload_url', 'Valid upload_url parameter is required.', ['status' => 400]);
        }

        $headers = is_array($params['headers'] ?? null) ? $params['headers'] : [];
        $includeDb = (bool) ($params['include_database'] ?? true);
        $includeFiles = (bool) ($params['include_files'] ?? true);
        $customExcludes = is_array($params['paths_to_exclude'] ?? null) ? $params['paths_to_exclude'] : [];

        $tempDir = Paths::stagingDir();

        $token = bin2hex(random_bytes(8));
        $dbDumpFile = $tempDir."/db-{$token}.sql.gz";
        $zipFile = $tempDir."/backup-{$token}.zip";

        try {
            // 1. Dump database
            if ($includeDb) {
                $dumper = new DatabaseDumper();
                $dumpResult = $dumper->dump($dbDumpFile);
                if (! ($dumpResult['ok'] ?? false)) {
                    return new WP_Error('db_dump_failed', $dumpResult['error'] ?? 'Database dump failed', ['status' => 500]);
                }
            }

            // 2. Package into Zip
            $archiver = new BackupArchiver();
            $archiveResult = $archiver->createArchive($zipFile, $dbDumpFile, $includeFiles, $customExcludes);
            if (! ($archiveResult['ok'] ?? false)) {
                return new WP_Error('archive_failed', $archiveResult['error'] ?? 'Archive creation failed', ['status' => 500]);
            }

            // 3. Upload directly to S3
            $uploader = new S3DirectUploader();
            $uploadResult = $uploader->upload($zipFile, $uploadUrl, $headers);
            if (! ($uploadResult['ok'] ?? false)) {
                return new WP_Error('upload_failed', $uploadResult['error'] ?? 'S3 upload failed', ['status' => 502]);
            }

            $duration = round(microtime(true) - $startTime, 2);

            return new WP_REST_Response([
                'ok' => true,
                'size_bytes' => $uploadResult['size_bytes'],
                'sha256' => $uploadResult['sha256'],
                'duration_seconds' => $duration,
            ], 200);
        } finally {
            // Guarantee cleanup of local temp files
            if (file_exists($dbDumpFile)) {
                @unlink($dbDumpFile);
            }
            if (file_exists($zipFile)) {
                @unlink($zipFile);
            }
        }
    }
}
