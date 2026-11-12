<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Sso\Interceptor;
use PHPUnit\Framework\TestCase;
use wpdb;

class SsoNonceLookupTest extends TestCase
{
    /** @var wpdb&object{rows: array<string, string>, lookups: int} */
    private wpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_transients'] = [];

        // Only the options table rows the SSO code touches.
        $this->db = new class extends wpdb {
            /** @var array<string, string> */
            public array $rows = [];

            public int $lookups = 0;

            public function get_var(?string $query = null, int $x = 0, int $y = 0): mixed
            {
                if ($query !== null && preg_match("/WHERE option_name = '([^']+)'/", $query, $m)) {
                    $this->lookups++;

                    return $this->rows[$m[1]] ?? null;
                }

                return null;
            }

            public function delete(string $table, array $where, array|string $where_format = []): int|false
            {
                $name = (string) ($where['option_name'] ?? '');
                if (! isset($this->rows[$name])) {
                    return 0;
                }
                unset($this->rows[$name]);

                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new wpdb();
        parent::tearDown();
    }

    public function testUnknownNonceIsLookedUpWithoutTheOptionsApi(): void
    {
        $nonce = str_repeat('ab', 24);

        $this->assertNull(Interceptor::claimNonce($nonce));
        $this->assertSame(1, $this->db->lookups);
        // get_option() would have cached the miss; the direct lookup leaves
        // no option state behind.
        $this->assertSame([], $GLOBALS['wp_test_options']);
    }

    public function testNonceIsSingleUse(): void
    {
        $nonce = str_repeat('cd', 24);
        $payload = ['user_id' => 3, 'expires_at' => time() + 60, 'redirect_to' => '/wp-admin/'];
        $this->db->rows['clockwork_sso_'.$nonce] = serialize($payload);

        $this->assertSame($payload, Interceptor::claimNonce($nonce));
        $this->assertNull(Interceptor::claimNonce($nonce));
    }
}
