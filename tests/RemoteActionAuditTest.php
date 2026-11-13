<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\Rest\PluginLifecycleRoute;
use ClockworkCompanion\Rest\SecretRotateRoute;
use ClockworkCompanion\Rest\SsoRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_User;
use wpdb;

class RemoteActionAuditTest extends TestCase
{
    /** @var wpdb&object{inserted: array<int, array{table: string, data: array<string, mixed>}>} */
    private wpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_transients'] = [];
        $GLOBALS['wp_test_users'] = [];
        $GLOBALS['wp_test_plugins'] = [];
        $GLOBALS['wp_test_active_plugins'] = [];
        $_SERVER['REMOTE_ADDR'] = '192.0.2.50';

        $this->db = new class extends wpdb {
            /** @var array<int, array{table: string, data: array<string, mixed>}> */
            public array $inserted = [];

            public function insert(string $table, array $data, array|string $format = []): int|false
            {
                $this->inserted[] = ['table' => $table, 'data' => $data];

                return 1;
            }

            public function get_var(?string $query = null, int $x = 0, int $y = 0): mixed
            {
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $actionType): array
    {
        $out = [];
        foreach ($this->db->inserted as $row) {
            if (str_ends_with($row['table'], 'action_log') && ($row['data']['action_type'] ?? '') === $actionType) {
                $out[] = $row['data'];
            }
        }

        return $out;
    }

    public function testSecretRotationIsRecordedWithoutTheSecret(): void
    {
        Secret::ensure();
        $response = (new SecretRotateRoute())->handle(new WP_REST_Request('POST', '/clockwork/v1/secret/rotate'));
        $secret = $response->get_data()['secret'];

        $rows = $this->rows('connection_key_rotation');
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['ok']);
        $this->assertSame('remote', $rows[0]['actor']);
        $this->assertStringNotContainsString($secret, (string) json_encode($rows[0]));
        $this->assertStringContainsString('192.0.2.50', (string) $rows[0]['details']);
    }

    public function testSsoMintIsRecorded(): void
    {
        $GLOBALS['wp_test_users'][7] = new WP_User('jane@example.com', 7, 'jane', ['administrator']);

        $response = (new SsoRoute())->handle(new WP_REST_Request('POST', '/clockwork/v1/sso/magic-link', [], [], '{"user_login":"jane"}'));
        $this->assertTrue($response->get_data()['ok']);

        $rows = $this->rows('sso_link_minted');
        $this->assertCount(1, $rows);
        $this->assertSame('jane', $rows[0]['target']);
        // The nonce in the link is a credential; it must not be logged.
        $nonce = substr((string) strrchr($response->get_data()['url'], '='), 1);
        $this->assertStringNotContainsString($nonce, (string) json_encode($rows[0]));
    }

    public function testPluginToggleIsRecordedIncludingRefusals(): void
    {
        $GLOBALS['wp_test_plugins'] = ['hello-dolly/hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7']];
        $route = new PluginLifecycleRoute();

        $route->handleToggle(new WP_REST_Request('POST', '/clockwork/v1/plugins/toggle', [], [], '{"slug":"hello-dolly/hello.php","action":"activate"}'));
        $route->handleToggle(new WP_REST_Request('POST', '/clockwork/v1/plugins/toggle', [], [], '{"slug":"clockwork-companion/clockwork-companion.php","action":"deactivate"}'));
        $route->handleToggle(new WP_REST_Request('POST', '/clockwork/v1/plugins/toggle', [], [], '{"slug":"../../x","action":"activate"}'));

        $rows = $this->rows('plugin_toggled');
        $this->assertCount(2, $rows, 'malformed input is not recorded');
        $this->assertSame('hello-dolly/hello.php', $rows[0]['target']);
        $this->assertSame(1, $rows[0]['ok']);
        $this->assertSame(0, $rows[1]['ok']);
    }

    public function testPluginDeleteIsRecorded(): void
    {
        $GLOBALS['wp_test_plugins'] = ['hello-dolly/hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7']];

        (new PluginLifecycleRoute())->handleDelete(new WP_REST_Request('POST', '/clockwork/v1/plugins/delete', [], [], '{"slug":"hello-dolly/hello.php"}'));

        $rows = $this->rows('plugin_deleted');
        $this->assertCount(1, $rows);
        $this->assertSame('hello-dolly/hello.php', $rows[0]['target']);
    }

    public function testPluginInstallIsRecorded(): void
    {
        (new PluginLifecycleRoute())->handleInstall(new WP_REST_Request('POST', '/clockwork/v1/plugins/install', [], [], '{"slug":"classic-editor"}'));

        $rows = $this->rows('plugin_installed');
        $this->assertCount(1, $rows);
        $this->assertSame('classic-editor/classic-editor.php', $rows[0]['target']);
        $this->assertStringContainsString('wordpress.org', (string) $rows[0]['details']);
    }
}
