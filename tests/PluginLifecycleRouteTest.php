<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\PluginLifecycleRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class PluginLifecycleRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_plugins'] = [
            'akismet/akismet.php' => [
                'Name'    => 'Akismet Anti-Spam',
                'Version' => '5.3.7',
            ],
            'hello-dolly/hello.php' => [
                'Name'    => 'Hello Dolly',
                'Version' => '1.7.2',
            ],
        ];
        $GLOBALS['wp_test_active_plugins'] = ['hello-dolly/hello.php'];
        unset($GLOBALS['wp_test_activate_plugin_error']);
        unset($GLOBALS['wp_test_delete_plugins_result']);
        unset($GLOBALS['wp_test_plugins_api_result']);
        unset($GLOBALS['wp_test_plugin_upgrader_install_result']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_test_activate_plugin_error']);
        unset($GLOBALS['wp_test_delete_plugins_result']);
        unset($GLOBALS['wp_test_plugins_api_result']);
        unset($GLOBALS['wp_test_plugin_upgrader_install_result']);
        parent::tearDown();
    }

    public function testRegisterRegistersAllLifecycleRoutes(): void
    {
        $route = new PluginLifecycleRoute();
        $route->register();

        $this->assertArrayHasKey(
            CLOCKWORK_COMPANION_NAMESPACE . '/plugins/toggle',
            $GLOBALS['wp_test_routes']
        );
        $this->assertArrayHasKey(
            CLOCKWORK_COMPANION_NAMESPACE . '/plugins/delete',
            $GLOBALS['wp_test_routes']
        );
        $this->assertArrayHasKey(
            CLOCKWORK_COMPANION_NAMESPACE . '/plugins/install',
            $GLOBALS['wp_test_routes']
        );
    }

    public function testToggleRejectsInvalidSlugOrAction(): void
    {
        $route = new PluginLifecycleRoute();

        // Missing slug
        $req = new WP_REST_Request('POST', '/clockwork-companion/v1/plugins/toggle', []);
        $res = $route->handleToggle($req);
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_input', $res->get_error_code());

        // Invalid slug shape
        $req = new WP_REST_Request('POST', '/clockwork-companion/v1/plugins/toggle', [
            'slug'   => '../../dangerous.php',
            'action' => 'activate',
        ]);
        $res = $route->handleToggle($req);
        $this->assertInstanceOf(\WP_Error::class, $res);

        // Invalid action
        $req = new WP_REST_Request('POST', '/clockwork-companion/v1/plugins/toggle', [
            'slug'   => 'akismet/akismet.php',
            'action' => 'destroy',
        ]);
        $res = $route->handleToggle($req);
        $this->assertInstanceOf(\WP_Error::class, $res);
    }

    public function testToggleReturns404ForUninstalledPlugin(): void
    {
        $route = new PluginLifecycleRoute();
        $req = new WP_REST_Request('POST', '/clockwork-companion/v1/plugins/toggle', [
            'slug'   => 'nonexistent/nonexistent.php',
            'action' => 'activate',
        ]);

        $res = $route->handleToggle($req);
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('plugin_not_found', $res->get_error_code());
    }

    public function testToggleActivatesAndDeactivatesPlugin(): void
    {
        $route = new PluginLifecycleRoute();

        // Activate
        $req = new WP_REST_Request('POST', '/clockwork-companion/v1/plugins/toggle', [
            'slug'   => 'akismet/akismet.php',
            'action' => 'activate',
        ]);
        $res = $route->handleToggle($req);
        $data = $res->get_data();

        $this->assertTrue($data['ok']);
        $this->assertSame('akismet/akismet.php', $data['slug']);
        $this->assertSame('activate', $data['action']);
        $this->assertTrue($data['active']);

        // Deactivate
        $reqDeact = new WP_REST_Request('POST', '/clockwork-companion/v1/plugins/toggle', [
            'slug'   => 'akismet/akismet.php',
            'action' => 'deactivate',
        ]);
        $resDeact = $route->handleToggle($reqDeact);
        $dataDeact = $resDeact->get_data();

        $this->assertTrue($dataDeact['ok']);
        $this->assertSame('deactivate', $dataDeact['action']);
        $this->assertFalse($dataDeact['active']);
    }

    public function testDeletePluginSafelyDeletes(): void
    {
        $route = new PluginLifecycleRoute();

        $req = new WP_REST_Request('POST', '/clockwork-companion/v1/plugins/delete', [
            'slug' => 'hello-dolly/hello.php',
        ]);
        $res = $route->handleDelete($req);
        $data = $res->get_data();

        $this->assertTrue($data['ok']);
        $this->assertSame('hello-dolly/hello.php', $data['slug']);
        $this->assertTrue($data['deleted']);
    }

    public function testInstallPluginFromWordPressOrg(): void
    {
        $route = new PluginLifecycleRoute();

        $req = new WP_REST_Request('POST', '/clockwork-companion/v1/plugins/install', [
            'slug'     => 'classic-editor',
            'activate' => true,
        ]);
        $res = $route->handleInstall($req);
        $data = $res->get_data();

        $this->assertTrue($data['ok']);
        $this->assertSame('classic-editor', $data['slug']);
        $this->assertSame('classic-editor/classic-editor.php', $data['plugin_file']);
        $this->assertSame('1.6.5', $data['version']);
        $this->assertTrue($data['activated']);
    }
}
