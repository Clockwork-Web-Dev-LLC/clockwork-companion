<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\PostUpdateVerifyRoute;
use ClockworkCompanion\Updates\Runner;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class PostUpdateVerifyRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_plugins'] = [
            'gravityforms/gravityforms.php' => ['Name' => 'Gravity Forms', 'Version' => '2.9.0'],
            'add-to-any/add-to-any.php' => ['Name' => 'AddToAny Share Buttons', 'Version' => '1.8.0'],
            'classic-editor/classic-editor.php' => ['Name' => 'Classic Editor', 'Version' => '1.6.7'],
            'disqus-comment-system/disqus.php' => ['Name' => 'Disqus for WordPress', 'Version' => '3.1.6'],
            'editorial-calendar/edcal.php' => ['Name' => 'Editorial Calendar', 'Version' => '3.8.6'],
            'wordpress-seo/wp-seo.php' => ['Name' => 'Yoast SEO', 'Version' => '26.2'],
            'nextgen-gallery/nggallery.php' => ['Name' => 'NextGEN Gallery', 'Version' => '4.5.4'],
        ];
        unset($GLOBALS['wp_test_activate_plugin_error']);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['wp_test_options']['active_plugins'],
            $GLOBALS['wp_test_active_plugins'],
            $GLOBALS['wp_test_activate_plugin_error'],
            $GLOBALS['wp_test_object_cache']
        );
        parent::tearDown();
    }

    public function testRestorePluginInOrderPreservesPinnedFirstAndNextGenLast(): void
    {
        $orderBefore = [
            'gravityforms/gravityforms.php',
            'add-to-any/add-to-any.php',
            'classic-editor/classic-editor.php',
            'disqus-comment-system/disqus.php',
            'editorial-calendar/edcal.php',
            'wordpress-seo/wp-seo.php',
            'nextgen-gallery/nggallery.php',
        ];

        // Simulate WordPress activate_plugin()'s sort($current) scrambling order.
        $sortedByActivatePlugin = $orderBefore;
        sort($sortedByActivatePlugin);

        $restored = Runner::restorePluginInOrder(
            $sortedByActivatePlugin,
            $orderBefore,
            'disqus-comment-system/disqus.php'
        );

        $this->assertSame($orderBefore, $restored);
        $this->assertTrue(array_is_list($restored));
    }

    public function testRestorePluginInOrderReinsertsMissingPluginIntoAlphabeticalSection(): void
    {
        $orderWithoutDisqus = [
            'gravityforms/gravityforms.php',
            'add-to-any/add-to-any.php',
            'classic-editor/classic-editor.php',
            'editorial-calendar/edcal.php',
            'wordpress-seo/wp-seo.php',
            'nextgen-gallery/nggallery.php',
        ];

        $sortedWithDisqus = array_merge($orderWithoutDisqus, ['disqus-comment-system/disqus.php']);
        sort($sortedWithDisqus);

        $restored = Runner::restorePluginInOrder(
            $sortedWithDisqus,
            $orderWithoutDisqus,
            'disqus-comment-system/disqus.php'
        );

        $this->assertSame([
            'gravityforms/gravityforms.php',
            'add-to-any/add-to-any.php',
            'classic-editor/classic-editor.php',
            'disqus-comment-system/disqus.php',
            'editorial-calendar/edcal.php',
            'wordpress-seo/wp-seo.php',
            'nextgen-gallery/nggallery.php',
        ], $restored);
    }

    public function testPostUpdateVerifyReactivatesMissingPluginAndPreservesLoadOrder(): void
    {
        $expectedOrder = [
            'gravityforms/gravityforms.php',
            'add-to-any/add-to-any.php',
            'classic-editor/classic-editor.php',
            'disqus-comment-system/disqus.php',
            'editorial-calendar/edcal.php',
            'wordpress-seo/wp-seo.php',
            'nextgen-gallery/nggallery.php',
        ];

        // Simulate Disqus having been deactivated during a plugin update window.
        $deactivatedState = [
            'gravityforms/gravityforms.php',
            'add-to-any/add-to-any.php',
            'classic-editor/classic-editor.php',
            'editorial-calendar/edcal.php',
            'wordpress-seo/wp-seo.php',
            'nextgen-gallery/nggallery.php',
        ];
        update_option('active_plugins', $deactivatedState);
        $GLOBALS['wp_test_active_plugins'] = $deactivatedState;
        $GLOBALS['wp_test_object_cache']['options:alloptions'] = ['active_plugins' => 'stale'];
        $GLOBALS['wp_test_object_cache']['options:active_plugins'] = 'stale';

        $route = new PostUpdateVerifyRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/post-update-verify');
        $request->set_body((string) json_encode([
            'active_plugins' => $expectedOrder,
            'stylesheet' => 'twentytwentyfive',
            'template' => 'twentytwentyfive',
        ]));

        $response = $route->handle($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertCount(1, $data['repairs']);
        $this->assertSame('plugin_reactivated', $data['repairs'][0]['type']);
        $this->assertSame('disqus-comment-system/disqus.php', $data['repairs'][0]['slug']);

        $persisted = get_option('active_plugins');
        $this->assertSame($expectedOrder, $persisted);
        $this->assertSame('gravityforms/gravityforms.php', $persisted[0]);
        $this->assertSame('nextgen-gallery/nggallery.php', end($persisted));
        $this->assertArrayNotHasKey('options:alloptions', $GLOBALS['wp_test_object_cache'] ?? []);
        $this->assertArrayNotHasKey('options:active_plugins', $GLOBALS['wp_test_object_cache'] ?? []);
    }

    public function testPostUpdateVerifyReactivatesNextGenGalleryAtEndOfLoadOrder(): void
    {
        $expectedOrder = [
            'gravityforms/gravityforms.php',
            'add-to-any/add-to-any.php',
            'classic-editor/classic-editor.php',
            'disqus-comment-system/disqus.php',
            'editorial-calendar/edcal.php',
            'wordpress-seo/wp-seo.php',
            'nextgen-gallery/nggallery.php',
        ];

        $withoutNgg = array_slice($expectedOrder, 0, -1);
        update_option('active_plugins', $withoutNgg);
        $GLOBALS['wp_test_active_plugins'] = $withoutNgg;

        $route = new PostUpdateVerifyRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/post-update-verify');
        $request->set_body((string) json_encode([
            'active_plugins' => $expectedOrder,
            'stylesheet' => 'twentytwentyfive',
            'template' => 'twentytwentyfive',
        ]));

        $response = $route->handle($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertCount(1, $data['repairs']);
        $this->assertSame('nextgen-gallery/nggallery.php', $data['repairs'][0]['slug']);
        $this->assertSame($expectedOrder, get_option('active_plugins'));
    }
}

