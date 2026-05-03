<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/comments-summary
 *
 * Read-only summary of the comment system. Designed to feed a future
 * /comments-policy toggle (Round 2) and a per-site Issues signal for
 * "moderation queue piling up" / "spam tsunami".
 *
 * Response:
 *   {
 *     "ok": true,
 *     "counts": {
 *       "approved": 1234,
 *       "awaiting_moderation": 0,
 *       "spam": 12,
 *       "trash": 0,
 *       "total": 1246
 *     },
 *     "spam_last_7_days": 47,
 *     "discussion": {
 *       "default_comment_status": "open",
 *       "default_ping_status": "open",
 *       "comment_registration": false,
 *       "close_comments_for_old_posts": true,
 *       "close_comments_days_old": 14
 *     },
 *     "akismet": {"active": true, "api_key_present": true},
 *     "antispam_plugins": ["akismet"]
 *   }
 */
class CommentsSummaryRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/comments-summary', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        global $wpdb;

        $counts = wp_count_comments();
        $sevenDaysAgo = gmdate('Y-m-d H:i:s', time() - (7 * 86400));
        $spamLast7 = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->comments}` WHERE comment_approved = %s AND comment_date_gmt >= %s",
            'spam',
            $sevenDaysAgo
        ));

        $akismetActive = is_plugin_active('akismet/akismet.php');
        $akismetKey = (string) get_option('wordpress_api_key', '');

        $antispam = [];
        if ($akismetActive) {
            $antispam[] = 'akismet';
        }
        if (is_plugin_active('antispam-bee/antispam_bee.php')) {
            $antispam[] = 'antispam-bee';
        }
        if (is_plugin_active('cleantalk-spam-protect/cleantalk.php')) {
            $antispam[] = 'cleantalk';
        }

        return [
            'ok' => true,
            'counts' => [
                'approved' => (int) ($counts->approved ?? 0),
                'awaiting_moderation' => (int) ($counts->moderated ?? 0),
                'spam' => (int) ($counts->spam ?? 0),
                'trash' => (int) ($counts->trash ?? 0),
                'total' => (int) ($counts->total_comments ?? 0),
            ],
            'spam_last_7_days' => $spamLast7,
            'discussion' => [
                'default_comment_status' => (string) get_option('default_comment_status', ''),
                'default_ping_status' => (string) get_option('default_ping_status', ''),
                'comment_registration' => (bool) get_option('comment_registration', false),
                'close_comments_for_old_posts' => (bool) get_option('close_comments_for_old_posts', false),
                'close_comments_days_old' => (int) get_option('close_comments_days_old', 0),
            ],
            'akismet' => [
                'active' => $akismetActive,
                'api_key_present' => $akismetKey !== '',
            ],
            'antispam_plugins' => $antispam,
        ];
    }
}
