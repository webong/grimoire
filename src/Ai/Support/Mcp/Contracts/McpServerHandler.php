<?php

namespace Webong\LaravelGouge\Ai\Support\Mcp\Contracts;

interface McpServerHandler
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
    ): array;
}
