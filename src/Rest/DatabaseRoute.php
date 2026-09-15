<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for database bloat summary and optimization.
 *
 * GET  /wp-json/clockwork/v1/database/summary
 * POST /wp-json/clockwork/v1/database/optimize
 */
class DatabaseRoute
{
    private const DEFAULT_MAX_ELAPSED_MS = 25000;

    private const DEFAULT_MAX_OPTIMIZE_TABLE_BYTES = 536870912; // 512 MiB

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
     * Lightweight telemetry for GET /snapshot — SHOW TABLE STATUS only.
     * Full bloat COUNTs stay on GET /database/summary.
     *
     * @return array<string, mixed>
     */
    public function snapshotPayload(): array
    {
        $status = $this->tableStatus();

        return [
            'ok'             => true,
            'table_count'    => $status['table_count'],
            'overhead_bytes' => $status['overhead_bytes'],
        ];
    }

    /**
     * Database bloat summary payload.
     *
     * @return array<string, mixed>
     */
    public function summaryPayload(): array
    {
        global $wpdb;

        $posts = $this->quotedTable('posts');
        $comments = $this->quotedTable('comments');
        $options = $this->quotedTable('options');
        $postmeta = $this->quotedTable('postmeta');
        $commentmeta = $this->quotedTable('commentmeta');
        $termmeta = $this->quotedTable('termmeta');
        $terms = $this->quotedTable('terms');

        $revisions = 0;
        $autoDrafts = 0;
        $trashedPosts = 0;
        $spamComments = 0;
        $trashedComments = 0;
        $expiredTransients = 0;
        $orphanedPostmeta = 0;
        $orphanedCommentmeta = 0;
        $orphanedTermmeta = 0;

        if ($posts !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$posts} WHERE post_type = 'revision'");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $autoDrafts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$posts} WHERE post_status = 'auto-draft'");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $trashedPosts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$posts} WHERE post_status = 'trash'");
        }

        if ($comments !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $spamComments = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$comments} WHERE comment_approved = 'spam'");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $trashedComments = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$comments} WHERE comment_approved = 'trash'");
        }

        if ($options !== null) {
            $now = time();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $expiredTransients = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d",
                $wpdb->esc_like('_transient_timeout_') . '%',
                $wpdb->esc_like('_site_transient_timeout_') . '%',
                $now
            ));
        }

        if ($postmeta !== null && $posts !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $orphanedPostmeta = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$postmeta} WHERE post_id NOT IN (SELECT ID FROM {$posts})");
        }

        if ($commentmeta !== null && $comments !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $orphanedCommentmeta = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$comments})");
        }

        if ($termmeta !== null && $terms !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $orphanedTermmeta = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$termmeta} WHERE term_id NOT IN (SELECT term_id FROM {$terms})");
        }

        $status = $this->tableStatus();

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
            'orphaned_termmeta'     => $orphanedTermmeta,
            'overhead_bytes'        => $status['overhead_bytes'],
            'fragmented_tables'     => $status['fragmented_tables'],
            'total_cleanable_items' => $revisions + $autoDrafts + $trashedPosts + $spamComments + $trashedComments + $expiredTransients + $orphanedPostmeta + $orphanedCommentmeta + $orphanedTermmeta,
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
        $dryRun           = ! empty($body['dry_run']);
        $maxElapsedMs     = max(1000, min(120000, (int) ($body['max_elapsed_ms'] ?? self::DEFAULT_MAX_ELAPSED_MS)));
        $maxTableBytes    = max(0, (int) ($body['max_optimize_table_bytes'] ?? self::DEFAULT_MAX_OPTIMIZE_TABLE_BYTES));
        $cursorIn         = is_array($body['cursor'] ?? null) ? $body['cursor'] : [];
        $cursorPhase      = (string) ($cursorIn['phase'] ?? 'revisions');
        $afterId          = max(0, (int) ($cursorIn['after_id'] ?? 0));
        $nextCursor       = null;

        $posts = $this->quotedTable('posts');
        $comments = $this->quotedTable('comments');
        $options = $this->quotedTable('options');
        $postmeta = $this->quotedTable('postmeta');
        $commentmeta = $this->quotedTable('commentmeta');
        $termmeta = $this->quotedTable('termmeta');
        $terms = $this->quotedTable('terms');

        $cleaned = [
            'revisions'            => 0,
            'auto_drafts'          => 0,
            'trashed_posts'        => 0,
            'spam_comments'        => 0,
            'trashed_comments'     => 0,
            'expired_transients'   => 0,
            'orphaned_postmeta'    => 0,
            'orphaned_commentmeta' => 0,
            'orphaned_termmeta'    => 0,
            'optimized_tables'     => 0,
            'reclaimed_bytes'      => 0,
            'skipped_large_tables' => 0,
        ];

        $incomplete = false;

        // 1. Revisions
        if ($cleanRevisions && $posts !== null && $this->phaseReady('revisions', $cursorPhase) && ! $this->timeExceeded($start, $maxElapsedMs)) {
            require_once ABSPATH . 'wp-admin/includes/revision.php';

            $resumeParent = $this->afterIdFor('revisions', $cursorPhase, $afterId);
            $lastParent = $resumeParent;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $parentIds = $wpdb->get_col(
                "SELECT DISTINCT post_parent FROM {$posts} WHERE post_type = 'revision' AND post_parent > {$resumeParent} ORDER BY post_parent ASC LIMIT 200"
            );
            if (is_array($parentIds)) {
                foreach ($parentIds as $parentId) {
                    if ($this->timeExceeded($start, $maxElapsedMs)) {
                        $incomplete = true;
                        $nextCursor = ['phase' => 'revisions', 'after_id' => $lastParent];
                        break;
                    }

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $revIds = $wpdb->get_col($wpdb->prepare(
                        "SELECT ID FROM {$posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC",
                        (int) $parentId
                    ));

                    if (is_array($revIds) && count($revIds) > $keepRevisions) {
                        $toDelete = array_slice($revIds, $keepRevisions);
                        foreach ($toDelete as $id) {
                            if ($this->timeExceeded($start, $maxElapsedMs)) {
                                $incomplete = true;
                                $nextCursor = ['phase' => 'revisions', 'after_id' => $lastParent];
                                break 2;
                            }
                            if ($dryRun || (function_exists('wp_delete_post_revision') && wp_delete_post_revision((int) $id))) {
                                $cleaned['revisions']++;
                            }
                        }
                    }
                    $lastParent = (int) $parentId;
                }
                if (! $incomplete && count($parentIds) === 200) {
                    $incomplete = true;
                    $nextCursor = ['phase' => 'revisions', 'after_id' => $lastParent];
                }
            }
        }

        // 2. Auto-drafts
        if ($cleanDrafts && $posts !== null && ! $incomplete && $this->phaseReady('drafts', $cursorPhase) && ! $this->timeExceeded($start, $maxElapsedMs)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $draftIds = $wpdb->get_col("SELECT ID FROM {$posts} WHERE post_status = 'auto-draft' LIMIT 500");
            if (is_array($draftIds)) {
                foreach ($draftIds as $id) {
                    if ($this->timeExceeded($start, $maxElapsedMs)) {
                        $incomplete = true;
                        break;
                    }
                    if ($dryRun || wp_delete_post((int) $id, true)) {
                        $cleaned['auto_drafts']++;
                    }
                }
            }
        } elseif ($cleanDrafts && $this->timeExceeded($start, $maxElapsedMs)) {
            $incomplete = true;
        }

        // 3. Trashed posts
        if ($cleanTrash && $posts !== null && ! $incomplete && $this->phaseReady('trash', $cursorPhase) && ! $this->timeExceeded($start, $maxElapsedMs)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $trashIds = $wpdb->get_col("SELECT ID FROM {$posts} WHERE post_status = 'trash' LIMIT 500");
            if (is_array($trashIds)) {
                foreach ($trashIds as $id) {
                    if ($this->timeExceeded($start, $maxElapsedMs)) {
                        $incomplete = true;
                        break;
                    }
                    if ($dryRun || wp_delete_post((int) $id, true)) {
                        $cleaned['trashed_posts']++;
                    }
                }
            }
        } elseif ($cleanTrash && $this->timeExceeded($start, $maxElapsedMs)) {
            $incomplete = true;
        }

        // 4. Spam & Trashed comments
        if ($cleanSpam && $comments !== null && ! $incomplete && $this->phaseReady('spam_comments', $cursorPhase) && ! $this->timeExceeded($start, $maxElapsedMs)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $spamIds = $wpdb->get_col("SELECT comment_ID FROM {$comments} WHERE comment_approved = 'spam' LIMIT 500");
            if (is_array($spamIds)) {
                foreach ($spamIds as $cid) {
                    if ($this->timeExceeded($start, $maxElapsedMs)) {
                        $incomplete = true;
                        break;
                    }
                    if ($dryRun || wp_delete_comment((int) $cid, true)) {
                        $cleaned['spam_comments']++;
                    }
                }
            }
            if (! $this->timeExceeded($start, $maxElapsedMs)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $trashCommentIds = $wpdb->get_col("SELECT comment_ID FROM {$comments} WHERE comment_approved = 'trash' LIMIT 500");
                if (is_array($trashCommentIds)) {
                    foreach ($trashCommentIds as $cid) {
                        if ($this->timeExceeded($start, $maxElapsedMs)) {
                            $incomplete = true;
                            break;
                        }
                        if ($dryRun || wp_delete_comment((int) $cid, true)) {
                            $cleaned['trashed_comments']++;
                        }
                    }
                }
            } else {
                $incomplete = true;
            }
        } elseif ($cleanSpam && $this->timeExceeded($start, $maxElapsedMs)) {
            $incomplete = true;
        }

        // 5. Expired transients (site + non-site)
        if ($cleanTransients && $options !== null && ! $incomplete && $this->phaseReady('transients', $cursorPhase) && ! $this->timeExceeded($start, $maxElapsedMs)) {
            $now = time();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $timeoutKeys = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d LIMIT 500",
                $wpdb->esc_like('_transient_timeout_') . '%',
                $wpdb->esc_like('_site_transient_timeout_') . '%',
                $now
            ));
            if (is_array($timeoutKeys)) {
                foreach ($timeoutKeys as $timeoutKey) {
                    if ($this->timeExceeded($start, $maxElapsedMs)) {
                        $incomplete = true;
                        break;
                    }
                    $timeoutKey = (string) $timeoutKey;
                    if (! $dryRun) {
                        if (str_starts_with($timeoutKey, '_site_transient_timeout_')) {
                            $transientName = substr($timeoutKey, strlen('_site_transient_timeout_'));
                            if (function_exists('delete_site_transient')) {
                                delete_site_transient($transientName);
                            }
                        } else {
                            $transientName = substr($timeoutKey, strlen('_transient_timeout_'));
                            delete_transient($transientName);
                        }
                    }
                    $cleaned['expired_transients']++;
                }
            }
        } elseif ($cleanTransients && $this->timeExceeded($start, $maxElapsedMs)) {
            $incomplete = true;
        }

        // 6. Orphaned postmeta, commentmeta, termmeta (batched, resumable)
        if ($cleanOrphaned && ! $incomplete && ! $this->timeExceeded($start, $maxElapsedMs)) {
            if ($postmeta !== null && $posts !== null && $this->phaseReady('orphaned_postmeta', $cursorPhase)) {
                $resumeMeta = $this->afterIdFor('orphaned_postmeta', $cursorPhase, $afterId);
                $batch = $this->deleteOrphanedMetaBatch(
                    $postmeta,
                    'post_id',
                    'ID',
                    $posts,
                    $resumeMeta,
                    $dryRun,
                    $start,
                    $maxElapsedMs
                );
                $cleaned['orphaned_postmeta'] = $batch['deleted'];
                if ($batch['incomplete']) {
                    $incomplete = true;
                    $nextCursor = ['phase' => 'orphaned_postmeta', 'after_id' => $batch['after_id']];
                }
            }

            if (! $incomplete && $commentmeta !== null && $comments !== null && $this->phaseReady('orphaned_commentmeta', $cursorPhase)) {
                $resumeMeta = $this->afterIdFor('orphaned_commentmeta', $cursorPhase, $afterId);
                $batch = $this->deleteOrphanedMetaBatch(
                    $commentmeta,
                    'comment_id',
                    'comment_ID',
                    $comments,
                    $resumeMeta,
                    $dryRun,
                    $start,
                    $maxElapsedMs
                );
                $cleaned['orphaned_commentmeta'] = $batch['deleted'];
                if ($batch['incomplete']) {
                    $incomplete = true;
                    $nextCursor = ['phase' => 'orphaned_commentmeta', 'after_id' => $batch['after_id']];
                }
            }

            if (! $incomplete && $termmeta !== null && $terms !== null && $this->phaseReady('orphaned_termmeta', $cursorPhase)) {
                $resumeMeta = $this->afterIdFor('orphaned_termmeta', $cursorPhase, $afterId);
                $batch = $this->deleteOrphanedMetaBatch(
                    $termmeta,
                    'term_id',
                    'term_id',
                    $terms,
                    $resumeMeta,
                    $dryRun,
                    $start,
                    $maxElapsedMs
                );
                $cleaned['orphaned_termmeta'] = $batch['deleted'];
                if ($batch['incomplete']) {
                    $incomplete = true;
                    $nextCursor = ['phase' => 'orphaned_termmeta', 'after_id' => $batch['after_id']];
                }
            }
        } elseif ($cleanOrphaned && $this->timeExceeded($start, $maxElapsedMs)) {
            $incomplete = true;
        }

        // 7. Optimize tables — skip rebuilds above the size threshold.
        if ($optimizeTables && ! $incomplete && $this->phaseReady('tables', $cursorPhase) && ! $this->timeExceeded($start, $maxElapsedMs)) {
            $status = $this->tableStatus();
            foreach ($status['rows'] as $row) {
                if ($this->timeExceeded($start, $maxElapsedMs)) {
                    $incomplete = true;
                    break;
                }

                $table = (string) ($row['Name'] ?? '');
                $dataFree = (int) ($row['Data_free'] ?? 0);
                $tableBytes = (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0);
                if ($dataFree <= 0 || ! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    continue;
                }
                if ($maxTableBytes > 0 && $tableBytes > $maxTableBytes) {
                    $cleaned['skipped_large_tables']++;
                    continue;
                }

                if (! $dryRun) {
                    $safeTable = esc_sql($table);
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query("OPTIMIZE TABLE `{$safeTable}`");
                }
                $cleaned['optimized_tables']++;
                $cleaned['reclaimed_bytes'] += $dataFree;
            }
        } elseif ($optimizeTables && $this->timeExceeded($start, $maxElapsedMs)) {
            $incomplete = true;
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf(
                '[clockwork-database] optimized database: cleaned %d revisions, %d drafts, %d tables in %dms%s',
                $cleaned['revisions'],
                $cleaned['auto_drafts'],
                $cleaned['optimized_tables'],
                $elapsedMs,
                $dryRun ? ' (dry_run)' : ''
            ));
        }

        return new WP_REST_Response([
            'ok'         => true,
            'cleaned'    => $cleaned,
            'elapsed_ms' => $elapsedMs,
            'dry_run'    => $dryRun,
            'incomplete' => $incomplete,
            'cursor'     => $incomplete ? $nextCursor : null,
        ]);
    }

    /**
     * @return array{table_count: int, overhead_bytes: int, fragmented_tables: array<int, array{table: string, overhead_bytes: int}>, rows: array<int, array<string, mixed>>}
     */
    private function tableStatus(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $tablesStatus = $wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->prefix}%'", ARRAY_A);
        $overheadBytes = 0;
        $fragmentedTables = [];
        $rows = [];

        if (is_array($tablesStatus)) {
            foreach ($tablesStatus as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[] = $row;
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
            'table_count'        => count($rows),
            'overhead_bytes'     => $overheadBytes,
            'fragmented_tables'  => $fragmentedTables,
            'rows'               => $rows,
        ];
    }

    private function quotedTable(string $property): ?string
    {
        global $wpdb;

        $name = isset($wpdb->{$property}) ? (string) $wpdb->{$property} : '';
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return null;
        }

        return '`' . $name . '`';
    }

    private function timeExceeded(float $start, int $maxElapsedMs): bool
    {
        return ((microtime(true) - $start) * 1000) >= $maxElapsedMs;
    }

    /**
     * @var list<string>
     */
    private const PHASES = [
        'revisions',
        'drafts',
        'trash',
        'spam_comments',
        'trashed_comments',
        'transients',
        'orphaned_postmeta',
        'orphaned_commentmeta',
        'orphaned_termmeta',
        'tables',
    ];

    private function phaseReady(string $phase, string $cursorPhase): bool
    {
        $order = array_flip(self::PHASES);

        return ($order[$phase] ?? 0) >= ($order[$cursorPhase] ?? 0);
    }

    private function afterIdFor(string $phase, string $cursorPhase, int $afterId): int
    {
        return $phase === $cursorPhase ? $afterId : 0;
    }

    /**
     * @return array{deleted: int, incomplete: bool, after_id: int}
     */
    private function deleteOrphanedMetaBatch(
        string $metaTable,
        string $fkColumn,
        string $parentIdColumn,
        string $parentTable,
        int $afterId,
        bool $dryRun,
        float $start,
        int $maxElapsedMs
    ): array {
        global $wpdb;

        if ($dryRun) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $deleted = max(0, (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$metaTable} WHERE {$fkColumn} NOT IN (SELECT {$parentIdColumn} FROM {$parentTable})"
            ));

            return ['deleted' => $deleted, 'incomplete' => false, 'after_id' => $afterId];
        }

        if ($this->timeExceeded($start, $maxElapsedMs)) {
            return ['deleted' => 0, 'incomplete' => true, 'after_id' => $afterId];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col(
            "SELECT meta_id FROM {$metaTable} WHERE meta_id > {$afterId} AND {$fkColumn} NOT IN (SELECT {$parentIdColumn} FROM {$parentTable}) ORDER BY meta_id ASC LIMIT 500"
        );
        if (! is_array($ids) || $ids === []) {
            return ['deleted' => 0, 'incomplete' => false, 'after_id' => $afterId];
        }

        $idList = implode(',', array_map('intval', $ids));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = max(0, (int) $wpdb->query("DELETE FROM {$metaTable} WHERE meta_id IN ({$idList})"));
        $lastId = (int) end($ids);
        $incomplete = count($ids) === 500 || $this->timeExceeded($start, $maxElapsedMs);

        return [
            'deleted'    => $deleted,
            'incomplete' => $incomplete,
            'after_id'   => $lastId,
        ];
    }
}
