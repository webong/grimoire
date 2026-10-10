<?php

namespace Webong\Gouge\Ai\Support\Mcp;

use Illuminate\Contracts\Container\Container;
use Webong\Gouge\Ai\Support\Mcp\Contracts\McpServerHandler;

class McpServerClient
{
    public function __construct(
        protected ?Container $container = null,
        protected ?McpRemoteEndpointClient $remoteEndpointClient = null,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function callTool(
        string $server,
        string $tool,
        array $arguments = [],
        array $context = [],
        ?string $idempotencyKey = null,
        ?int $timeoutMs = null
    ): array {
        $transport = is_string($context['transport'] ?? null)
            ? strtolower((string) $context['transport'])
            : 'http';
        $mode = is_string($context['mode'] ?? null)
            ? strtolower((string) $context['mode'])
            : 'remote';

        if ($mode === 'local' || $transport === 'local') {
            return $this->callLocal(
                server: $server,
                tool: $tool,
                arguments: $arguments,
                context: $context,
                idempotencyKey: $idempotencyKey,
                timeoutMs: $timeoutMs,
            );
        }

        if (in_array($transport, ['http', 'websocket', 'remote'], true)) {
            return $this->callRemote(
                server: $server,
                tool: $tool,
                arguments: $arguments,
                context: $context,
                idempotencyKey: $idempotencyKey,
                timeoutMs: $timeoutMs,
            );
        }

        return [
            'ok' => false,
            'error_code' => 'unsupported_transport',
            'error_message' => "Unsupported MCP transport [{$transport}].",
            'status' => 500,
            'data' => null,
            'latency_ms' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function callLocal(
        string $server,
        string $tool,
        array $arguments,
        array $context,
        ?string $idempotencyKey,
        ?int $timeoutMs
    ): array {
        $handlerClass = $context['handler'] ?? null;

        if (! is_string($handlerClass) || trim($handlerClass) === '') {
            return [
                'ok' => false,
                'error_code' => 'missing_handler',
                'error_message' => 'No MCP server handler is configured for this server.',
                'status' => 500,
                'data' => null,
                'latency_ms' => null,
            ];
        }

        if (! class_exists($handlerClass)) {
            return [
                'ok' => false,
                'error_code' => 'handler_not_found',
                'error_message' => "Configured MCP handler [{$handlerClass}] does not exist.",
                'status' => 500,
                'data' => null,
                'latency_ms' => null,
            ];
        }

        $app = $this->container ?? app();
        $handler = $app->make($handlerClass);

        if (! $handler instanceof McpServerHandler) {
            return [
                'ok' => false,
                'error_code' => 'invalid_handler',
                'error_message' => "Configured MCP handler [{$handlerClass}] must implement ".McpServerHandler::class.'.',
                'status' => 500,
                'data' => null,
                'latency_ms' => null,
            ];
        }

        $configuredDefaultTimeout = (int) ($context['default_timeout_ms'] ?? 8000);
        $configuredMaxTimeout = (int) ($context['max_timeout_ms'] ?? 30000);
        $resolvedTimeoutMs = $timeoutMs ?? $configuredDefaultTimeout;
        $resolvedTimeoutMs = max(250, min($configuredMaxTimeout, $resolvedTimeoutMs));

        $startedAt = microtime(true);

        try {
            $payload = $handler->callTool(
                tool: $tool,
                arguments: $arguments,
                context: $context,
                idempotencyKey: $idempotencyKey,
                timeoutMs: $resolvedTimeoutMs,
            );
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'error_code' => 'handler_exception',
                'error_message' => $exception->getMessage(),
                'status' => 500,
                'data' => null,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        if (! is_array($payload)) {
            return [
                'ok' => false,
                'error_code' => 'invalid_handler_response',
                'error_message' => "MCP handler [{$handlerClass}] returned an invalid response shape.",
                'status' => 500,
                'data' => null,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        $latencyMs = $payload['latency_ms'] ?? (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'ok' => (bool) ($payload['ok'] ?? false),
            'error_code' => $payload['error_code'] ?? null,
            'error_message' => $payload['error_message'] ?? null,
            'status' => (int) ($payload['status'] ?? 200),
            'data' => $payload['data'] ?? $payload,
            'latency_ms' => is_numeric($latencyMs) ? (int) $latencyMs : null,
            'server' => $server,
            'handler' => $handlerClass,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function callRemote(
        string $server,
        string $tool,
        array $arguments,
        array $context,
        ?string $idempotencyKey,
        ?int $timeoutMs
    ): array {
        $endpointUrl = $context['endpoint_url'] ?? null;

        if (! is_string($endpointUrl) || trim($endpointUrl) === '') {
            return [
                'ok' => false,
                'error_code' => 'missing_endpoint_url',
                'error_message' => 'No remote endpoint URL is configured for this server.',
                'status' => 500,
                'data' => null,
                'latency_ms' => null,
            ];
        }

        $configuredDefaultTimeout = (int) ($context['default_timeout_ms'] ?? 8000);
        $configuredMaxTimeout = (int) ($context['max_timeout_ms'] ?? 30000);
        $resolvedTimeoutMs = $timeoutMs ?? $configuredDefaultTimeout;
        $resolvedTimeoutMs = max(250, min($configuredMaxTimeout, $resolvedTimeoutMs));

        $headers = is_array($context['headers'] ?? null) ? $context['headers'] : [];

        $startedAt = microtime(true);
        $client = $this->remoteEndpointClient ?? app(McpRemoteEndpointClient::class);
        $payload = $client->callTool(
            endpointUrl: trim($endpointUrl),
            tool: $tool,
            arguments: $arguments,
            headers: $headers,
            idempotencyKey: $idempotencyKey,
            timeoutMs: $resolvedTimeoutMs,
        );

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'ok' => (bool) ($payload['ok'] ?? false),
            'error_code' => $payload['error_code'] ?? null,
            'error_message' => $payload['error_message'] ?? null,
            'status' => (int) ($payload['status'] ?? 500),
            'data' => $payload['data'] ?? null,
            'latency_ms' => $payload['latency_ms'] ?? $latencyMs,
            'server' => $server,
            'endpoint_url' => $endpointUrl,
        ];
    }
}
