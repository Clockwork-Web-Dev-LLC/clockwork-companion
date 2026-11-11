<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Support\RestRequest;
use PHPUnit\Framework\TestCase;

class RestRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        unset($GLOBALS['wp'], $GLOBALS['wp_test_home_url'], $_SERVER['REQUEST_URI']);
        $_GET = [];
        $_POST = [];
    }

    private function parsed(mixed $route): void
    {
        $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => $route]];
    }

    public function testDispatchedRouteIsNullUntilWordPressHasParsedTheRequest(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/clockwork/v1/health';

        $this->assertNull(RestRequest::dispatchedRoute());
    }

    public function testDispatchedRouteComesFromTheParsedQueryVarNotTheUrl(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users?x=/wp-json/clockwork/v1/';
        $this->parsed('/wp/v2/users');

        $this->assertSame('/wp/v2/users', RestRequest::dispatchedRoute());
        $this->assertFalse(RestRequest::inNamespace(RestRequest::dispatchedRoute(), ['clockwork/v1']));
    }

    public function testDispatchedRouteIgnoresEmptyAndNonStringValues(): void
    {
        $this->parsed('');
        $this->assertNull(RestRequest::dispatchedRoute());

        $this->parsed(['/clockwork/v1/health']);
        $this->assertNull(RestRequest::dispatchedRoute());
    }

    public function testInNamespaceRequiresASegmentBoundaryAndIgnoresCase(): void
    {
        $this->assertTrue(RestRequest::inNamespace('/clockwork/v1/health', ['clockwork/v1']));
        $this->assertTrue(RestRequest::inNamespace('/clockwork/v1', ['clockwork/v1']));
        $this->assertTrue(RestRequest::inNamespace('/CLOCKWORK/V1/health', ['clockwork/v1']));

        $this->assertFalse(RestRequest::inNamespace('/clockwork/v10/health', ['clockwork/v1']));
        $this->assertFalse(RestRequest::inNamespace('/wp/v2/users/clockwork/v1/x', ['clockwork/v1']));
        $this->assertFalse(RestRequest::inNamespace('/', ['clockwork/v1']));
        $this->assertFalse(RestRequest::inNamespace(null, ['clockwork/v1']));
    }

    public function testAnticipatedRouteReadsPrettyAndPlainPermalinkForms(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/clockwork/v1/health?_cw=123';
        $this->assertSame('/clockwork/v1/health', RestRequest::anticipatedRoute());

        $_SERVER['REQUEST_URI'] = '/index.php/wp-json/clockwork/v1/health';
        $this->assertSame('/clockwork/v1/health', RestRequest::anticipatedRoute());

        $_SERVER['REQUEST_URI'] = '/?rest_route=/clockwork/v1/health';
        $_GET = ['rest_route' => '/clockwork/v1/health'];
        $this->assertSame('/clockwork/v1/health', RestRequest::anticipatedRoute());
    }

    public function testAnticipatedRouteHonoursASubdirectoryInstall(): void
    {
        $GLOBALS['wp_test_home_url'] = 'https://example.test/blog';
        $_SERVER['REQUEST_URI'] = '/blog/wp-json/clockwork/v1/health';

        $this->assertSame('/clockwork/v1/health', RestRequest::anticipatedRoute());
    }

    /**
     * WP::parse_request() lets a `rest_route` in $_POST or $_GET override the
     * permalink, so the path alone does not say which route will run.
     */
    public function testAnticipatedRouteLetsTheQueryVarOverrideThePath(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/clockwork/v1/health?rest_route=/wp/v2/users';
        $_GET = ['rest_route' => '/wp/v2/users'];

        $this->assertSame('/wp/v2/users', RestRequest::anticipatedRoute());

        $_POST = ['rest_route' => '/wp/v2/posts'];
        $this->assertSame('/wp/v2/posts', RestRequest::anticipatedRoute());
    }

    public function testAnticipatedRouteIsNullForNonRestAndNonFrontControllerRequests(): void
    {
        $_SERVER['REQUEST_URI'] = '/some-page/?x=/wp-json/clockwork/v1/';
        $this->assertNull(RestRequest::anticipatedRoute());

        $_SERVER['REQUEST_URI'] = '/blog/wp-json/clockwork/v1/health';
        $this->assertNull(RestRequest::anticipatedRoute());

        // These entry points never dispatch REST, whatever the query string says.
        $_GET = ['rest_route' => '/clockwork/v1/health'];
        foreach (['/wp-login.php', '/xmlrpc.php', '/wp-admin/', '/wp-admin/admin-ajax.php'] as $path) {
            $_SERVER['REQUEST_URI'] = $path.'?rest_route=/clockwork/v1/health';
            $this->assertNull(RestRequest::anticipatedRoute(), $path);
        }
    }

    public function testAnticipatedRoutePrefersTheParsedRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/clockwork/v1/health';
        $this->parsed('/wp/v2/users/');

        $this->assertSame('/wp/v2/users', RestRequest::anticipatedRoute());
    }
}
