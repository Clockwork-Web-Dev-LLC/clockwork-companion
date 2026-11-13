<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\LockoutsRoute;
use ClockworkCompanion\Rest\ReportedBlocks;
use ClockworkCompanion\Rest\WordfenceBlocksRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use wpdb;

class ReportedBlocksTest extends TestCase
{
    /** @var wpdb&object{llar: array<string, array<string, int>>, network: array<string, int>, wf: array<int, array<string, mixed>>} */
    private wpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_is_multisite'] = false;
        $GLOBALS['wp_test_site_ids'] = [1];
        $this->db = new class extends wpdb {
            public string $sitemeta = 'wp_sitemeta';

            public string $base_prefix = 'wp_';

            /** @var array<string, array<string, int>> options table => LLAR lockouts */
            public array $llar = [];

            /** @var array<string, int> */
            public array $network = [];

            /** @var array<int, array<string, mixed>> */
            public array $wf = [];

            public function suppress_errors(bool $suppress = true): bool
            {
                return true;
            }

            public function get_var(?string $query = null, int $x = 0, int $y = 0): mixed
            {
                $query = (string) $query;
                if (str_starts_with($query, 'SHOW TABLES LIKE')) {
                    return str_contains($query, 'wfBlocks7') && $this->wf !== [] ? $this->base_prefix.'wfBlocks7' : null;
                }
                if (preg_match('/FROM `([a-z0-9_]+)` WHERE option_name/', $query, $m)) {
                    return isset($this->llar[$m[1]]) ? serialize($this->llar[$m[1]]) : null;
                }
                if (str_contains($query, 'wp_sitemeta')) {
                    return $this->network !== [] ? serialize($this->network) : null;
                }

                return null;
            }

            public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
            {
                return str_contains((string) $query, 'wfBlocks7') ? $this->wf : [];
            }
        };
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_test_is_multisite'] = false;
        $GLOBALS['wpdb'] = new wpdb();
        parent::tearDown();
    }

    public function testNonPublicAddressesAreNeverReported(): void
    {
        foreach (['10.0.0.5', '127.0.0.1', '::1', '169.254.10.10', '100.64.1.1', 'fc00::5', '0.0.0.0', '192.168.1.1'] as $ip) {
            $this->assertFalse(ReportedBlocks::isBannable($ip), $ip);
        }
        $this->assertTrue(ReportedBlocks::isBannable('203.0.113.9'));
        $this->assertTrue(ReportedBlocks::isBannable('2001:db8::9'));
    }

    public function testLockoutsAreFilteredAndCapped(): void
    {
        $future = time() + 600;
        $rows = ['10.1.2.3' => $future, '127.0.0.1' => $future, '198.51.100.20' => $future];
        for ($i = 0; $i < 600; $i++) {
            $rows['203.0.'.intdiv($i, 250).'.'.($i % 250 + 1)] = $future;
        }
        $this->db->llar['wp_options'] = $rows;

        $data = (new LockoutsRoute())->handle(new WP_REST_Request('GET', '/clockwork/v1/lockouts'))->get_data();
        $ips = array_column($data['lockouts'], 'ip');

        $this->assertCount(ReportedBlocks::MAX_ROWS, $ips);
        $this->assertTrue($data['truncated']);
        $this->assertNotContains('10.1.2.3', $ips);
        $this->assertNotContains('127.0.0.1', $ips);
        $this->assertContains('198.51.100.20', $ips);
    }

    public function testMultisiteGetReadsEveryBlogAndTheNetworkOption(): void
    {
        $GLOBALS['wp_test_is_multisite'] = true;
        $GLOBALS['wp_test_site_ids'] = [1, 2];
        $future = time() + 600;
        $this->db->llar['wp_options'] = ['203.0.113.1' => $future];
        $this->db->llar['wp_2_options'] = ['203.0.113.2' => $future];
        $this->db->network = ['203.0.113.3' => $future];

        $data = (new LockoutsRoute())->handle(new WP_REST_Request('GET', '/clockwork/v1/lockouts'))->get_data();
        $ips = array_column($data['lockouts'], 'ip');
        sort($ips);

        $this->assertSame(['203.0.113.1', '203.0.113.2', '203.0.113.3'], $ips);
    }

    public function testWordfenceReasonIsTreatedAsUntrusted(): void
    {
        $this->db->wf = [
            ['ip' => '203.0.113.50', 'type' => 'manual', 'reason' => "Blocked\x1b[31m\nby admin".str_repeat('x', 500), 'expiration' => 0],
            ['ip' => '10.0.0.9', 'type' => 'manual', 'reason' => 'internal', 'expiration' => 0],
        ];

        $data = (new WordfenceBlocksRoute())->handle(new WP_REST_Request('GET', '/clockwork/v1/wordfence-blocks'))->get_data();

        $this->assertCount(1, $data['blocks']);
        $reason = $data['blocks'][0]['reason'];
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $reason);
        $this->assertLessThanOrEqual(200, mb_strlen($reason));
    }
}
