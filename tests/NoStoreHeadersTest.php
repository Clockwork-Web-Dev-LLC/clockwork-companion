<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\NoStoreHeaders;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

class NoStoreHeadersTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOwnNamespaceResponsesAreMarkedNoStore(): void
    {
        foreach ([200, 401, 500] as $status) {
            $response = NoStoreHeaders::apply(
                new WP_REST_Response(['ok' => $status === 200], $status),
                null,
                new WP_REST_Request('GET', '/clockwork/v1/snapshot')
            );

            $this->assertStringContainsString('no-store', $response->get_headers()['Cache-Control']);
            $this->assertStringContainsString('private', $response->get_headers()['Cache-Control']);
            $this->assertSame('no-cache', $response->get_headers()['Pragma']);
        }
        $this->assertTrue(defined('DONOTCACHEPAGE'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOtherNamespacesAreLeftAlone(): void
    {
        $response = NoStoreHeaders::apply(new WP_REST_Response([]), null, new WP_REST_Request('GET', '/wp/v2/posts'));

        $this->assertSame([], $response->get_headers());
        $this->assertFalse(defined('DONOTCACHEPAGE'));
    }

    public function testRegisteredOnRestPostDispatch(): void
    {
        $GLOBALS['wp_test_filters']['rest_post_dispatch'] = [];
        NoStoreHeaders::register();

        $this->assertContains([NoStoreHeaders::class, 'apply'], $GLOBALS['wp_test_filters']['rest_post_dispatch']);
    }
}
