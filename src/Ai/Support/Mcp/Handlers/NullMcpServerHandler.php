<?php

namespace Webong\LaravelGouge\Ai\Support\Mcp\Handlers;

use Webong\LaravelGouge\Ai\Support\Mcp\Contracts\McpServerHandler;

class NullMcpServerHandler implements McpServerHandler
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function callTool(
        string $tool,
        array $arguments = [],
        array $context = [],
        ?string $idempotencyKey = null,
        ?int $timeoutMs = null
    ): array {
        return [
            'ok' => false,
            'status' => 501,
            'error_code' => 'handler_not_implemented',
            'error_message' => 'MCP handler is configured as NullMcpServerHandler. Provide a concrete server handler.',
            'data' => [
                'tool' => $tool,
                'idempotency_key' => $idempotencyKey,
            ],
        ];
    }
}
