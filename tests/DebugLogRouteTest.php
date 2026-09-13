<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\DebugLogRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class DebugLogRouteTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            @unlink($this->logFile);
        }
        parent::tearDown();
    }

    public function testRegisterRegistersGetAndDeleteRoutes(): void
    {
        $route = new DebugLogRoute();
        $route->register();

        $this->assertArrayHasKey(
            CLOCKWORK_COMPANION_NAMESPACE . '/debug-log',
            $GLOBALS['wp_test_routes']
        );
    }

    public function testHandleGetReturnsEmptyWhenNoLogExists(): void
    {
        $route = new DebugLogRoute();
        $request = new WP_REST_Request('GET', '/clockwork-companion/v1/debug-log');

        $response = $route->handleGet($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertFalse($data['exists']);
        $this->assertSame(0, $data['line_count']);
        $this->assertSame([], $data['lines']);
    }

    public function testHandleGetTailsFileAndMasksSensitiveData(): void
    {
        $testLines = [];
        for ($i = 1; $i <= 50; $i++) {
            $testLines[] = "[13-Sep-2026 12:00:{$i} UTC] Notice: log entry {$i}";
        }
        $testLines[] = "[13-Sep-2026 12:01:00 UTC] Fatal error in " . ABSPATH . "wp-config.php with password='SuperSecretDbPassword123' and Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9";

        file_put_contents($this->logFile, implode("
", $testLines) . "
");

        $route = new DebugLogRoute();
        $request = new WP_REST_Request('GET', '/clockwork-companion/v1/debug-log', ['lines' => 10]);

        $response = $route->handleGet($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['exists']);
        $this->assertSame(10, $data['line_count']);
        $this->assertCount(10, $data['lines']);

        // Check sensitive data masking on the last line
        $lastLine = end($data['lines']);
        $this->assertStringContainsString('/ABSPATH/', $lastLine);
        $this->assertStringNotContainsString(ABSPATH, $lastLine);
        $this->assertStringContainsString('password=[REDACTED]', $lastLine);
        $this->assertStringNotContainsString('SuperSecretDbPassword123', $lastLine);
        $this->assertStringContainsString('Bearer [REDACTED]', $lastLine);
    }

    public function testHandleClearTruncatesFile(): void
    {
        file_put_contents($this->logFile, "Some debug error line
Another error line
");
        $this->assertGreaterThan(0, filesize($this->logFile));

        $route = new DebugLogRoute();
        $request = new WP_REST_Request('DELETE', '/clockwork-companion/v1/debug-log');

        $response = $route->handleClear($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['cleared']);
        $this->assertSame(0, filesize($this->logFile));
    }
}
