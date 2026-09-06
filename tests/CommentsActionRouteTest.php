<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\CommentsActionRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class CommentsActionRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_comments'] = [
            (object) [
                'comment_ID' => 101,
                'comment_post_ID' => 10,
                'comment_author' => 'Alice',
                'comment_author_email' => 'alice@example.com',
                'comment_author_IP' => '192.0.2.1',
                'comment_author_url' => '',
                'comment_date_gmt' => '2026-09-01 12:00:00',
                'comment_content' => 'Great article!',
                'comment_approved' => '1',
            ],
            (object) [
                'comment_ID' => 102,
                'comment_post_ID' => 10,
                'comment_author' => 'Bob',
                'comment_author_email' => 'bob@example.com',
                'comment_author_IP' => '192.0.2.2',
                'comment_author_url' => '',
                'comment_date_gmt' => '2026-09-02 12:00:00',
                'comment_content' => 'Pending review comment',
                'comment_approved' => '0',
            ],
            (object) [
                'comment_ID' => 103,
                'comment_post_ID' => 10,
                'comment_author' => 'Spammer',
                'comment_author_email' => 'spam@example.com',
                'comment_author_IP' => '192.0.2.3',
                'comment_author_url' => '',
                'comment_date_gmt' => '2026-09-03 12:00:00',
                'comment_content' => 'Buy cheap stuff',
                'comment_approved' => 'spam',
            ],
        ];
    }

    public function testHandleListReturnsCommentsWithCounts(): void
    {
        $route = new CommentsActionRoute();
        $request = new WP_REST_Request('GET', '/clockwork/v1/comments', ['status' => 'all']);
        $response = $route->handleList($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertCount(3, $data['items']);
        $this->assertSame(1, $data['counts']['approved']);
        $this->assertSame(1, $data['counts']['awaiting_moderation']);
        $this->assertSame(1, $data['counts']['spam']);
        $this->assertSame('approved', $data['items'][0]['status']);
    }

    public function testHandleModerateRejectsInvalidAction(): void
    {
        $route = new CommentsActionRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/comments/moderate', [
            'ids' => [101],
            'action' => 'hack',
        ]);
        $response = $route->handleModerate($request);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['ok']);
        $this->assertSame('invalid_action', $data['error']);
    }

    public function testHandleModerateRejectsEmptyIds(): void
    {
        $route = new CommentsActionRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/comments/moderate', [
            'ids' => [],
            'action' => 'approve',
        ]);
        $response = $route->handleModerate($request);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['ok']);
        $this->assertSame('missing_ids', $data['error']);
    }

    public function testHandleModerateApprovesPendingComment(): void
    {
        $route = new CommentsActionRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/comments/moderate', [
            'ids' => [102],
            'action' => 'approve',
        ]);
        $response = $route->handleModerate($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertSame(1, $data['updated']);
        $this->assertEmpty($data['failed']);
        $this->assertSame('1', $GLOBALS['wp_test_comments'][1]->comment_approved);
    }

    public function testHandleModerateSpamsAndDeletesComments(): void
    {
        $route = new CommentsActionRoute();
        
        // Spam
        $spamRequest = new WP_REST_Request('POST', '/clockwork/v1/comments/moderate', [
            'ids' => [101],
            'action' => 'spam',
        ]);
        $spamResponse = $route->handleModerate($spamRequest);
        $this->assertSame(200, $spamResponse->get_status());
        $this->assertSame('spam', $GLOBALS['wp_test_comments'][0]->comment_approved);

        // Delete permanently
        $deleteRequest = new WP_REST_Request('POST', '/clockwork/v1/comments/moderate', [
            'ids' => [103],
            'action' => 'delete',
        ]);
        $deleteResponse = $route->handleModerate($deleteRequest);
        $this->assertSame(200, $deleteResponse->get_status());
        $this->assertCount(2, $GLOBALS['wp_test_comments']);
    }
}
