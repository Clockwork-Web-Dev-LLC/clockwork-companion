<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\CodeSnippetRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class CodeSnippetRouteTest extends TestCase
{
    public function testHandleRejectsEmptyCode(): void
    {
        $route = new CodeSnippetRoute();
        $response = $route->handle(new WP_REST_Request('POST', '/clockwork/v1/code-snippet', ['code' => '   ']));

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['ok']);
        $this->assertSame('empty_code', $data['error']);
    }

    public function testHandleExecutesCodeAndReturnsValue(): void
    {
        $route = new CodeSnippetRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/code-snippet', [
            'code' => 'return 40 + 2;',
        ]);
        $response = $route->handle($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertSame(42, $data['result']);
        $this->assertEmpty($data['output']);
        $this->assertIsFloat($data['duration_ms']);
    }

    public function testHandleCapturesPrintedOutput(): void
    {
        $route = new CodeSnippetRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/code-snippet', [
            'code' => 'echo "Hello Clockwork"; return true;',
        ]);
        $response = $route->handle($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['result']);
        $this->assertSame('Hello Clockwork', $data['output']);
    }

    public function testHandleCatchesThrowableException(): void
    {
        $route = new CodeSnippetRoute();
        $request = new WP_REST_Request('POST', '/clockwork/v1/code-snippet', [
            'code' => 'throw new \RuntimeException("Test failure");',
        ]);
        $response = $route->handle($request);

        $this->assertSame(422, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['ok']);
        $this->assertSame('execution_error', $data['error']);
        $this->assertSame('Test failure', $data['exception']['message']);
    }

    public function testRunIsRecordedOnTheSiteByHashNotByCode(): void
    {
        $db = new class extends \wpdb {
            /** @var array<int, array<string, mixed>> */
            public array $inserted = [];

            public function insert(string $table, array $data, array|string $format = []): int|false
            {
                $this->inserted[] = $data;

                return 1;
            }
        };
        $previous = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $db;
        $GLOBALS['wp_test_transients'] = [];

        try {
            $code = 'return "marker-" . (20 + 1);';
            (new CodeSnippetRoute())->handle(new WP_REST_Request('POST', '/clockwork/v1/code-snippet', ['code' => $code]));
        } finally {
            $GLOBALS['wpdb'] = $previous;
        }

        $types = array_column($db->inserted, 'action_type');
        $this->assertSame(['code_snippet_run', 'code_snippet_result'], $types);
        $this->assertStringContainsString(hash('sha256', $code), (string) $db->inserted[0]['details']);
        $this->assertStringNotContainsString('marker-', (string) json_encode($db->inserted));
        $this->assertSame(1, $db->inserted[1]['ok']);
    }
}
