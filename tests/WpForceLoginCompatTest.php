<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Compat\WpForceLoginCompat;
use PHPUnit\Framework\TestCase;

/**
 * The compat filter returns true from `rest_authentication_errors`, which
 * also switches off core's REST cookie-nonce (CSRF) check for that request.
 * It must therefore fire only for a request WordPress is really dispatching
 * to one of our own, signature-gated routes.
 */
class WpForceLoginCompatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_filters'] = [];
        $GLOBALS['wp_test_current_user_set'] = [];
        unset($GLOBALS['wp']);
        WpForceLoginCompat::register();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_test_filters'] = [];
        unset($GLOBALS['wp'], $_SERVER['REQUEST_URI']);
        parent::tearDown();
    }

    private function filter(string $uri, ?string $parsedRoute, mixed $incoming = null): mixed
    {
        $_SERVER['REQUEST_URI'] = $uri;
        if ($parsedRoute === null) {
            unset($GLOBALS['wp']);
        } else {
            $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => $parsedRoute]];
        }

        return apply_filters('rest_authentication_errors', $incoming);
    }

    public function testOwnRouteIsAllowedPastForceLogin(): void
    {
        $this->assertTrue($this->filter('/wp-json/clockwork/v1/health', '/clockwork/v1/health'));
        $this->assertTrue($this->filter('/?rest_route=/clockwork/v1/health', '/clockwork/v1/health'));
    }

    public function testOwnRouteIsServedAsAnAnonymousUser(): void
    {
        $this->filter('/wp-json/clockwork/v1/health', '/clockwork/v1/health');

        $this->assertSame([0], $GLOBALS['wp_test_current_user_set']);
    }

    /**
     * The regression: a core route with our namespace mentioned in the query
     * string used to return true, skipping the nonce check for the logged-in
     * administrator who followed the link.
     */
    public function testNamespaceInTheQueryStringDoesNotBypassCoreChecks(): void
    {
        $uri = '/wp-json/wp/v2/users?_method=POST&username=x&roles[]=administrator&x=/wp-json/clockwork/v1/';

        $this->assertNull($this->filter($uri, '/wp/v2/users'));
        $this->assertSame([], $GLOBALS['wp_test_current_user_set']);
    }

    public function testRestRouteParameterTextDoesNotBypassCoreChecks(): void
    {
        $this->assertNull($this->filter('/wp-json/wp/v2/posts?x=rest_route=/clockwork/v1/', '/wp/v2/posts'));
    }

    public function testQueryVarOverridingAClockworkPathIsNotOurs(): void
    {
        $this->assertNull($this->filter('/wp-json/clockwork/v1/health?rest_route=/wp/v2/users', '/wp/v2/users'));
    }

    public function testUnparsedRequestIsLeftAlone(): void
    {
        $this->assertNull($this->filter('/wp-json/clockwork/v1/health', null));
    }

    public function testAnEarlierResultIsPassedThroughUntouched(): void
    {
        $error = new \stdClass();

        $this->assertSame($error, $this->filter('/wp-json/clockwork/v1/health', '/clockwork/v1/health', $error));
        $this->assertSame([], $GLOBALS['wp_test_current_user_set']);
    }
}
