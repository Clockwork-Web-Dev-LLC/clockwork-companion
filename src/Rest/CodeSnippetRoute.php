<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\ActionLog\Repository as ActionLogRepository;
use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Support\OperationLock;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

class CodeSnippetRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/code-snippet', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (self::isDisabled()) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'disabled',
                'message' => 'Code snippets are disabled on this site (CLOCKWORK_COMPANION_DISABLE_CODE_SNIPPETS).',
            ], 403);
        }

        $params = (array) $request->get_json_params();
        $code = trim((string) ($params['code'] ?? ''));
        $timeout = min(60, max(5, (int) ($params['timeout'] ?? 30)));

        if ($code === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'empty_code',
                'message' => 'PHP code cannot be empty',
            ], 400);
        }

        // Normalize leading PHP tag
        if (str_starts_with($code, '<?php')) {
            $code = substr($code, 5);
        } elseif (str_starts_with($code, '<?')) {
            $code = substr($code, 2);
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        if (! OperationLock::acquire('code_snippet', $timeout + 5)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'busy',
                'message' => 'Another code snippet is already running',
            ], 429);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout);
        }

        ob_start();
        $returnValue = null;
        $error = null;

        try {
            // Isolated closure only scopes the variable. eval() still runs
            // with full WordPress privileges — HMAC is the gate.
            $runner = function () use ($code) {
                return eval($code);
            };
            $returnValue = $runner();
        } catch (Throwable $t) {
            $error = [
                'message' => $t->getMessage(),
                'file' => basename($t->getFile()),
                'line' => $t->getLine(),
                'class' => get_class($t),
            ];
        } finally {
            OperationLock::release('code_snippet');
        }

        $output = (string) ob_get_clean();
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $memoryBytes = max(0, memory_get_usage() - $startMemory);

        // Audit log recording if repository exists
        if (class_exists(ActionLogRepository::class) && method_exists(ActionLogRepository::class, 'append')) {
            try {
                ActionLogRepository::append(
                    'code_snippet',
                    $error ? 'failed' : 'success',
                    [
                        'duration_ms' => $durationMs,
                        'has_output' => $output !== '',
                        'has_error' => $error !== null,
                    ]
                );
            } catch (Throwable) {}
        }

        if ($error !== null) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'execution_error',
                'exception' => $error,
                'output' => $output,
                'duration_ms' => $durationMs,
            ], 422);
        }

        return new WP_REST_Response([
            'ok' => true,
            'result' => $returnValue,
            'output' => $output,
            'duration_ms' => $durationMs,
            'memory_bytes' => $memoryBytes,
        ]);
    }

    public static function isDisabled(): bool
    {
        return defined('CLOCKWORK_COMPANION_DISABLE_CODE_SNIPPETS')
            && constant('CLOCKWORK_COMPANION_DISABLE_CODE_SNIPPETS');
    }
}
