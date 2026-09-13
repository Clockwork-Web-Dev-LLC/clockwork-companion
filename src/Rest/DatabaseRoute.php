<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for database bloat summary and optimization.
 *
 * GET  /wp-json/clockwork-renegade/v1/database/summary
 * POST /wp-json/clockwork-renegade/v1/database/optimize
 */
class DatabaseRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/database/summary', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleSummary'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);

        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/database/optimize', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleOptimize'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handleSummary(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->summaryPayload());
    }

    /**
     * Database bloat summary payload.
     *
     * @return array<string, mixed>
     */
    public function summaryPayload(): array
    {
        global $wpdb;

        // Revisions count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM  WHERE post_type = 'revision'");

        // Auto-drafts count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $autoDrafts = (int) $wpdb->get_var("SELECT COUNT(*) FROM  WHERE post_status = 'auto-draft'");

        // Trashed posts count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $trashedPosts = (int) $wpdb->get_var("SELECT COUNT(*) FROM  WHERE post_status = 'trash'");

        // Spam and trashed comments count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $spamComments = (int) $wpdb->get_var("SELECT COUNT(*) FROM  WHERE comment_approved = 'spam'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $trashedComments = (int) $wpdb->get_var("SELECT COUNT(*) FROM  WHERE comment_approved = 'trash'");

        // Expired transients
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $expiredTransients = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM  WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
            $wpdb->esc_like('_transient_timeout_') . '%',
            $now
        ));

        // Orphaned postmeta
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $orphanedPostmeta = (int) $wpdb->get_var("SELECT COUNT(*) FROM  WHERE post_id NOT IN (SELECT ID FROM )");

        // Orphaned commentmeta
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $orphanedCommentmeta = (int) $wpdb->get_var("SELECT COUNT(*) FROM  WHERE comment_id NOT IN (SELECT comment_ID FROM )");

        // Table overhead and fragmentation
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $tablesStatus = $wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->prefix}%'", ARRAY_A);
        $overheadBytes = 0;
        $fragmentedTables = [];
        if (is_array($tablesStatus)) {
            foreach ($tablesStatus as $row) {
                $dataFree = (int) ($row['Data_free'] ?? 0);
                if ($dataFree > 0) {
                    $overheadBytes += $dataFree;
                    $fragmentedTables[] = [
                        'table'          => (string) ($row['Name'] ?? ''),
                        'overhead_bytes' => $dataFree,
                    ];
                }
            }
        }

        return [
            'ok'                    => true,
            'revisions'             => $revisions,
            'auto_drafts'           => $autoDrafts,
            'trashed_posts'         => $trashedPosts,
            'spam_comments'         => $spamComments,
            'trashed_comments'      => $trashedComments,
            'expired_transients'    => $expiredTransients,
            'orphaned_postmeta'     => $orphanedPostmeta,
            'orphaned_commentmeta'  => $orphanedCommentmeta,
            'overhead_bytes'        => $overheadBytes,
            'fragmented_tables'     => $fragmentedTables,
            'total_cleanable_items' => $revisions + $autoDrafts + $trashedPosts + $spamComments + $trashedComments + $expiredTransients + $orphanedPostmeta + $orphanedCommentmeta,
        ];
    }

    public function handleOptimize(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $start = microtime(true);
        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            $body = [];
        }

        $cleanRevisions   = (bool) ($body['revisions'] ?? true);
        $keepRevisions    = max(0, (int) ($body['keep_revisions'] ?? 5));
        $cleanDrafts      = (bool) ($body['drafts'] ?? true);
        $cleanTrash       = (bool) ($body['trash'] ?? true);
        $cleanSpam        = (bool) ($body['spam_comments'] ?? true);
        $cleanTransients  = (bool) ($body['transients'] ?? true);
        $cleanOrphaned    = (bool) ($body['orphaned_meta'] ?? true);
        $optimizeTables   = (bool) ($body['tables'] ?? true);

        $cleaned = [
            'revisions'            => 0,
            'auto_drafts'          => 0,
            'trashed_posts'        => 0,
            'spam_comments'        => 0,
            'trashed_comments'     => 0,
            'expired_transients'   => 0,
            'orphaned_postmeta'    => 0,
            'orphaned_commentmeta' => 0,
            'optimized_tables'     => 0,
            'reclaimed_bytes'      => 0,
        ];

        // 1. Revisions
        if ($cleanRevisions) {
            require_once ABSPATH . 'wp-admin/includes/revision.php';

            // Find parent posts with revisions
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $parentIds = $wpdb->get_col("SELECT DISTINCT post_parent FROM  WHERE post_type = 'revision' AND post_parent > 0 LIMIT 200");
            if (is_array($parentIds)) {
                foreach ($parentIds as $parentId) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $revIds = $wpdb->get_col($wpdb->prepare(
                        "SELECT ID FROM  WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC",
                        (int) $parentId
                    ));

                    if (is_array($revIds) && count($revIds) > $keepRevisions) {
                        $toDelete = array_slice($revIds, $keepRevisions);
                        foreach ($toDelete as $id) {
                            if (function_exists('wp_delete_post_revision')) {
                                wp_delete_post_revision((int) $id);
                                $cleaned['revisions']++;
                            }
                        }
                    }
                }
            }
        }

        // 2. Auto-drafts
        if ($cleanDrafts) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $draftIds = $wpdb->get_col("SELECT ID FROM  WHERE post_status = 'auto-draft' LIMIT 500");
            if (is_array($draftIds)) {
                foreach ($draftIds as $id) {
                    wp_delete_post((int) $id, true);
                    $cleaned['auto_drafts']++;
                }
            }
        }

        // 3. Trashed posts
        if ($cleanTrash) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $trashIds = $wpdb->get_col("SELECT ID FROM  WHERE post_status = 'trash' LIMIT 500");
            if (is_array($trashIds)) {
                foreach ($trashIds as $id) {
                    wp_delete_post((int) $id, true);
                    $cleaned['trashed_posts']++;
                }
            }
        }

        // 4. Spam & Trashed comments
        if ($cleanSpam) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $spamIds = $wpdb->get_col("SELECT comment_ID FROM  WHERE comment_approved = 'spam' LIMIT 500");
            if (is_array($spamIds)) {
                foreach ($spamIds as $cid) {
                    wp_delete_comment((int) $cid, true);
                    $cleaned['spam_comments']++;
                }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $trashCommentIds = $wpdb->get_col("SELECT comment_ID FROM  WHERE comment_approved = 'trash' LIMIT 500");
            if (is_array($trashCommentIds)) {
                foreach ($trashCommentIds as $cid) {
                    wp_delete_comment((int) $cid, true);
                    $cleaned['trashed_comments']++;
                }
            }
        }

        // 5. Expired transients
        if ($cleanTransients) {
            $now = time();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $timeoutKeys = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM  WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 500",
                $wpdb->esc_like('_transient_timeout_') . '%',
                $now
            ));
            if (is_array($timeoutKeys)) {
                foreach ($timeoutKeys as $timeoutKey) {
                    $transientName = substr($timeoutKey, strlen('_transient_timeout_'));
                    delete_transient($transientName);
                    $cleaned['expired_transients']++;
                }
            }
        }

        // 6. Orphaned postmeta & commentmeta
        if ($cleanOrphaned) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $deletedMeta = $wpdb->query("DELETE FROM  WHERE post_id NOT IN (SELECT ID FROM )");
            $cleaned['orphaned_postmeta'] = max(0, (int) $deletedMeta);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $deletedCommentmeta = $wpdb->query("DELETE FROM  WHERE comment_id NOT IN (SELECT comment_ID FROM )");
            $cleaned['orphaned_commentmeta'] = max(0, (int) $deletedCommentmeta);
        }

        // 7. Optimize tables
        if ($optimizeTables) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $tablesStatus = $wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->prefix}%'", ARRAY_A);
            if (is_array($tablesStatus)) {
                foreach ($tablesStatus as $row) {
                    $table = (string) ($row['Name'] ?? '');
                    $dataFree = (int) ($row['Data_free'] ?? 0);
                    if ($dataFree > 0 && preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $wpdb->query("OPTIMIZE TABLE ");
                        $cleaned['optimized_tables']++;
                        $cleaned['reclaimed_bytes'] += $dataFree;
                    }
                }
            }
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf(
                '[clockwork-database] optimized database: cleaned %d revisions, %d drafts, %d tables in %dms',
                $cleaned['revisions'],
                $cleaned['auto_drafts'],
                $cleaned['optimized_tables'],
                $elapsedMs
            ));
        }

        return new WP_REST_Response([
            'ok'         => true,
            'cleaned'    => $cleaned,
            'elapsed_ms' => $elapsedMs,
        ]);
    }
}
