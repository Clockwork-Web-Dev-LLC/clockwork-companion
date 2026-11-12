<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\Updates\TransientRefresher;
use PHPUnit\Framework\TestCase;
use WP_Error;

class TransientRefresherBackoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_transients'] = [];
        $GLOBALS['wp_test_remote_post_calls'] = [];
        Secret::ensure();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_test_remote_post']);
        parent::tearDown();
    }

    public function testFailedLoopbackIsNotRetriedUntilTheBackoffLapses(): void
    {
        $GLOBALS['wp_test_remote_post'] = static fn () => new WP_Error('http_request_failed', 'Operation timed out after 30001 milliseconds');

        TransientRefresher::forceRefresh();
        TransientRefresher::forceRefresh();
        TransientRefresher::refresh();

        $this->assertCount(1, $GLOBALS['wp_test_remote_post_calls']);
        $this->assertNotFalse(get_transient(TransientRefresher::FAILURE_TRANSIENT));
    }

    public function testNonJsonSuccessCountsAsFailure(): void
    {
        $GLOBALS['wp_test_remote_post'] = static fn () => ['response' => ['code' => 200], 'body' => '<html>Login</html>'];

        TransientRefresher::forceRefresh();

        $this->assertNotFalse(get_transient(TransientRefresher::FAILURE_TRANSIENT));
    }

    public function testWorkingLoopbackKeepsRefreshing(): void
    {
        $GLOBALS['wp_test_remote_post'] = static fn () => ['response' => ['code' => 200], 'body' => '{"success":true,"data":{"refreshed_at":1}}'];

        TransientRefresher::forceRefresh();
        TransientRefresher::forceRefresh();

        $this->assertCount(2, $GLOBALS['wp_test_remote_post_calls']);
        $this->assertFalse(get_transient(TransientRefresher::FAILURE_TRANSIENT));
    }
}
