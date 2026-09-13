<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\DatabaseRoute;
use ClockworkCompanion\Rest\SnapshotRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class DatabaseRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new class extends \wpdb {
            public int $varCallCount = 0;
            public function get_var(?string $query = null, int $x = 0, int $y = 0): mixed
            {
                $this->varCallCount++;
                return 42;
            }

            public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
            {
                return [
                    [
                        'Name'      => 'wp_posts',
                        'Data_free' => 10240,
                    ],
                ];
            }

            public function get_col(?string $query = null, int $x = 0): array
            {
                return [101, 102];
            }

            public function query(string $query): int
            {
                $this->queries[] = $query;
                return 3;
            }
        };
    }

    public function testRegisterRegistersRoutes(): void
    {
        $route = new DatabaseRoute();
        $route->register();

        $this->assertArrayHasKey(
            CLOCKWORK_COMPANION_NAMESPACE . '/database/summary',
            $GLOBALS['wp_test_routes']
        );
        $this->assertArrayHasKey(
            CLOCKWORK_COMPANION_NAMESPACE . '/database/optimize',
            $GLOBALS['wp_test_routes']
        );
    }

    public function testHandleSummaryReturnsBloatBreakdown(): void
    {
        $route = new DatabaseRoute();
        $request = new WP_REST_Request('GET', '/clockwork-companion/v1/database/summary');

        $response = $route->handleSummary($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertSame(42, $data['revisions']);
        $this->assertSame(42, $data['auto_drafts']);
        $this->assertSame(42, $data['trashed_posts']);
        $this->assertSame(42, $data['spam_comments']);
        $this->assertSame(10240, $data['overhead_bytes']);
        $this->assertCount(1, $data['fragmented_tables']);
        $this->assertGreaterThan(0, $data['total_cleanable_items']);
    }

    public function testHandleOptimizePerformsCleanup(): void
    {
        $route = new DatabaseRoute();
        $request = new WP_REST_Request('POST', '/clockwork-companion/v1/database/optimize');
        $request->set_body((string) json_encode([
            'revisions'      => true,
            'keep_revisions' => 1,
            'drafts'         => true,
            'trash'          => true,
            'spam_comments'  => true,
            'transients'     => true,
            'orphaned_meta'  => true,
            'tables'         => true,
        ]));

        $response = $route->handleOptimize($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertIsArray($data['cleaned']);
        $this->assertArrayHasKey('revisions', $data['cleaned']);
        $this->assertArrayHasKey('optimized_tables', $data['cleaned']);
        $this->assertArrayHasKey('reclaimed_bytes', $data['cleaned']);
        $this->assertSame(1, $data['cleaned']['optimized_tables']);
        $this->assertSame(10240, $data['cleaned']['reclaimed_bytes']);
        $this->assertIsInt($data['elapsed_ms']);
    }

    public function testSnapshotIncludesDatabasePayload(): void
    {
        $snapshot = new SnapshotRoute();
        $request = new WP_REST_Request('GET', '/clockwork-companion/v1/snapshot');
        $response = $snapshot->handle($request);
        $data = $response->get_data();

        $this->assertArrayHasKey('database', $data);
        $this->assertTrue($data['database']['ok']);
        $this->assertSame(42, $data['database']['revisions']);
        $this->assertSame(10240, $data['database']['overhead_bytes']);
    }
}
