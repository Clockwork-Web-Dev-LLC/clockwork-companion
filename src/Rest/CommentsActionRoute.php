<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for centralized comment management and moderation.
 *
 * GET  /wp-json/clockwork/v1/comments           - List comments with status/search filtering
 * POST /wp-json/clockwork/v1/comments/moderate  - Batch moderate (approve, hold, spam, trash, delete)
 * POST /wp-json/clockwork/v1/comments/cleanup   - Bulk clean spam/trash older than N days
 */
class CommentsActionRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/comments', [
            'methods' => 'GET',
            'callback' => [$this, 'handleList'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);

        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/comments/moderate', [
            'methods' => 'POST',
            'callback' => [$this, 'handleModerate'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);

        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/comments/cleanup', [
            'methods' => 'POST',
            'callback' => [$this, 'handleCleanup'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handleList(WP_REST_Request $request): WP_REST_Response
    {
        $status = sanitize_text_field((string) ($request->get_param('status') ?? 'all'));
        $page = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $search = sanitize_text_field((string) ($request->get_param('search') ?? ''));

        $allowedStatuses = ['all', 'approve', 'hold', 'spam', 'trash'];
        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'all';
        }

        $queryArgs = [
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
        ];

        if ($status !== 'all') {
            $queryArgs['status'] = $status;
        }

        if ($search !== '') {
            $queryArgs['search'] = $search;
        }

        $comments = get_comments($queryArgs);
        $counts = wp_count_comments();

        $items = [];
        foreach ($comments as $c) {
            $rawPostUrl = (string) (get_permalink((int) $c->comment_post_ID) ?: '');
            $safePostUrl = preg_match('#^https?://#i', $rawPostUrl) === 1 ? $rawPostUrl : '';
            $authorName = (string) $c->comment_author;
            $dateGmt = (string) $c->comment_date_gmt;

            $items[] = [
                'id' => (int) $c->comment_ID,
                'post_id' => (int) $c->comment_post_ID,
                'post_title' => get_the_title((int) $c->comment_post_ID) ?: 'Untitled',
                'post_url' => $safePostUrl,
                'author' => $authorName,
                'author_name' => $authorName,
                'author_email' => (string) $c->comment_author_email,
                'author_ip' => (string) $c->comment_author_IP,
                'author_url' => (string) $c->comment_author_url,
                'date_gmt' => $dateGmt,
                'date' => $dateGmt,
                'content' => (string) $c->comment_content,
                'status' => $this->normalizeStatus((string) $c->comment_approved),
            ];
        }

        $approvedCount = (int) ($counts->approved ?? 0);
        $moderatedCount = (int) ($counts->moderated ?? 0);
        $spamCount = (int) ($counts->spam ?? 0);
        $trashCount = (int) ($counts->trash ?? 0);
        $totalComments = (int) ($counts->total_comments ?? ($approvedCount + $moderatedCount));

        $filteredTotal = match ($status) {
            'approve' => $approvedCount,
            'hold' => $moderatedCount,
            'spam' => $spamCount,
            'trash' => $trashCount,
            default => $totalComments,
        };

        return new WP_REST_Response([
            'ok' => true,
            'items' => $items,
            'comments' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $filteredTotal,
            'total_pages' => (int) max(1, (int) ceil($filteredTotal / $perPage)),
            'counts' => [
                'all' => $totalComments,
                'approved' => $approvedCount,
                'moderated' => $moderatedCount,
                'awaiting_moderation' => $moderatedCount,
                'spam' => $spamCount,
                'trash' => $trashCount,
                'total' => $totalComments,
            ],
        ]);
    }

    public function handleModerate(WP_REST_Request $request): WP_REST_Response
    {
        $params = (array) $request->get_json_params();
        $ids = (array) ($params['ids'] ?? $params['comment_ids'] ?? []);
        $action = sanitize_text_field((string) ($params['action'] ?? ''));

        $allowedActions = ['approve', 'hold', 'spam', 'trash', 'delete'];
        if (! in_array($action, $allowedActions, true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'invalid_action',
                'message' => 'Action must be one of: approve, hold, spam, trash, delete',
            ], 400);
        }

        if (empty($ids)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'missing_ids',
                'message' => 'ids array must contain at least one comment ID',
            ], 400);
        }

        $updated = 0;
        $failed = [];

        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }

            $success = false;
            if ($action === 'delete') {
                $success = (bool) wp_delete_comment($id, true);
            } elseif ($action === 'trash') {
                $success = (bool) wp_trash_comment($id);
            } elseif ($action === 'spam') {
                $success = (bool) wp_spam_comment($id);
            } elseif ($action === 'approve') {
                $success = (bool) wp_set_comment_status($id, 'approve');
            } elseif ($action === 'hold') {
                $success = (bool) wp_set_comment_status($id, 'hold');
            }

            if ($success) {
                $updated++;
            } else {
                $failed[] = $id;
            }
        }

        return new WP_REST_Response([
            'ok' => true,
            'action' => $action,
            'updated' => $updated,
            'success_count' => $updated,
            'failed_count' => count($failed),
            'failed' => $failed,
        ]);
    }

    public function handleCleanup(WP_REST_Request $request): WP_REST_Response
    {
        $params = (array) $request->get_json_params();
        $target = sanitize_text_field((string) ($params['target'] ?? 'both'));
        $olderThanDays = max(0, (int) ($params['older_than_days'] ?? 30));

        $allowedTargets = ['spam', 'trash', 'both'];
        if (! in_array($target, $allowedTargets, true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'invalid_target',
                'message' => 'Target must be one of: spam, trash, both',
            ], 400);
        }

        global $wpdb;
        $statuses = [];
        if ($target === 'spam' || $target === 'both') {
            $statuses[] = 'spam';
        }
        if ($target === 'trash' || $target === 'both') {
            $statuses[] = 'trash';
        }

        $cutoffDate = gmdate('Y-m-d H:i:s', time() - ($olderThanDays * 86400));
        $remainingBudget = 500;
        $purgedSpam = 0;
        $purgedTrash = 0;

        foreach ($statuses as $statusKey) {
            if ($remainingBudget <= 0) {
                break;
            }

            $commentIds = $wpdb->get_col($wpdb->prepare(
                "SELECT comment_ID FROM `{$wpdb->comments}` WHERE comment_approved = %s AND comment_date_gmt <= %s LIMIT %d",
                $statusKey,
                $cutoffDate,
                $remainingBudget
            ));

            foreach ((array) $commentIds as $id) {
                if (wp_delete_comment((int) $id, true)) {
                    if ($statusKey === 'spam') {
                        $purgedSpam++;
                    } else {
                        $purgedTrash++;
                    }
                    $remainingBudget--;
                    if ($remainingBudget <= 0) {
                        break;
                    }
                }
            }
        }

        $deleted = $purgedSpam + $purgedTrash;

        return new WP_REST_Response([
            'ok' => true,
            'target' => $target,
            'older_than_days' => $olderThanDays,
            'deleted' => $deleted,
            'total_purged' => $deleted,
            'purged_spam' => $purgedSpam,
            'purged_trash' => $purgedTrash,
        ]);
    }

    private function normalizeStatus(string $approved): string
    {
        return match ($approved) {
            '1' => 'approved',
            '0' => 'pending',
            'spam' => 'spam',
            'trash' => 'trash',
            default => $approved,
        };
    }
}
