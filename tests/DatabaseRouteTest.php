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
                if (is_string($query)) {
                    $this->queries[] = $query;
                }
                $this->varCallCount++;
                return 42;
            }

            public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
            {
                if (is_string($query)) {
                    $this->queries[] = $query;
                }
                return [
                    [
                        'Name'         => 'wp_posts',
                        'Data_free'    => 10240,
                        'Data_length'  => 1024,
                        'Index_length' => 512,
                    ],
                ];
            }

            public function get_col(?string $query = null, int $x = 0): array
            {
                if (is_string($query)) {
                    $this->queries[] = $query;
                }
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

    public function testSummaryQueriesUseCoreTableIdentifiers(): void
    {
        (new DatabaseRoute())->summaryPayload();
        $joined = implode("\n", $GLOBALS['wpdb']->queries);

        $this->assertStringContainsString('`wp_posts`', $joined);
        $this->assertStringContainsString('`wp_comments`', $joined);
        $this->assertStringContainsString('`wp_options`', $joined);
        $this->assertStringContainsString('`wp_postmeta`', $joined);
        $this->assertStringContainsString('`wp_commentmeta`', $joined);
        $this->assertStringContainsString('`wp_termmeta`', $joined);
        $this->assertStringContainsString('`wp_terms`', $joined);
        $this->assertDoesNotMatchRegularExpression('/FROM\s+WHERE/', $joined);
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
        $this->assertFalse($data['dry_run']);
        $this->assertFalse($data['incomplete']);
        $this->assertIsArray($data['cleaned']);
        $this->assertArrayHasKey('revisions', $data['cleaned']);
        $this->assertArrayHasKey('optimized_tables', $data['cleaned']);
        $this->assertArrayHasKey('reclaimed_bytes', $data['cleaned']);
        $this->assertSame(1, $data['cleaned']['optimized_tables']);
        $this->assertSame(10240, $data['cleaned']['reclaimed_bytes']);
        $this->assertIsInt($data['elapsed_ms']);

        $joined = implode("\n", $GLOBALS['wpdb']->queries);
        $this->assertStringContainsString('OPTIMIZE TABLE `wp_posts`', $joined);
        $this->assertStringContainsString('`wp_posts`', $joined);
        $this->assertDoesNotMatchRegularExpression('/FROM\s+WHERE/', $joined);
    }

    public function testHandleOptimizeDryRunDoesNotMutateAndSkipsHugeTables(): void
    {
        $GLOBALS['wpdb'] = new class extends \wpdb {
            public function get_var(?string $query = null, int $x = 0, int $y = 0): mixed
            {
                return 7;
            }

            public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
            {
                return [
                    [
                        'Name'         => 'wp_postmeta',
                        'Data_free'    => 999,
                        'Data_length'  => 600000000,
                        'Index_length' => 100000000,
                    ],
                ];
            }

            public function get_col(?string $query = null, int $x = 0): array
            {
                return [201, 202];
            }

            public function query(string $query): int
            {
                $this->queries[] = $query;
                return 3;
            }
        };

        $route = new DatabaseRoute();
        $request = new WP_REST_Request('POST', '/clockwork-companion/v1/database/optimize');
        $request->set_body((string) json_encode([
            'dry_run' => true,
            'tables'  => true,
        ]));

        $response = $route->handleOptimize($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['dry_run']);
        $this->assertSame(0, $data['cleaned']['optimized_tables']);
        $this->assertSame(1, $data['cleaned']['skipped_large_tables']);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testSnapshotIncludesLightweightDatabasePayload(): void
    {
        $snapshot = new SnapshotRoute();
        $request = new WP_REST_Request('GET', '/clockwork-companion/v1/snapshot');
        $response = $snapshot->handle($request);
        $data = $response->get_data();

        $this->assertArrayHasKey('database', $data);
        $this->assertTrue($data['database']['ok']);
        $this->assertArrayNotHasKey('revisions', $data['database']);
        $this->assertSame(1, $data['database']['table_count']);
        $this->assertSame(10240, $data['database']['overhead_bytes']);
    }

    public function testHandleOptimizeResumesOrphanedMetaFromCursor(): void
    {
        $route = new DatabaseRoute();
        $request = new WP_REST_Request('POST', '/clockwork-companion/v1/database/optimize');
        $request->set_body((string) json_encode([
            'revisions'     => false,
            'drafts'        => false,
            'trash'         => false,
            'spam_comments' => false,
            'transients'    => false,
            'tables'        => false,
            'orphaned_meta' => true,
            'cursor'        => [
                'phase'    => 'orphaned_postmeta',
                'after_id' => 50,
            ],
        ]));

        $response = $route->handleOptimize($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $joined = implode("\n", $GLOBALS['wpdb']->queries);
        $this->assertStringContainsString('meta_id > 50', $joined);
        $this->assertStringContainsString('`wp_postmeta`', $joined);
    }
}
