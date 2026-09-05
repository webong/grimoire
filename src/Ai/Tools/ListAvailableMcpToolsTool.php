<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\LaravelGouge\Ai\Support\Mcp\McpRegistry;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;

class ListAvailableMcpToolsTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
        protected McpRegistry $serverResolver = new McpRegistry,
    ) {}

    public function description(): Stringable|string
    {
        return 'List MCP servers available in current thread/space/user context and the allowlisted tools for each.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $servers = $this->serverResolver->available($this->thread, $this->actor);

        $items = collect($servers)->map(function (array $server): array {
            return [
                'server' => $server['server'] ?? null,
                'transport' => $server['transport'] ?? 'remote',
                'tools' => $server['tools'] ?? [],
                'context_source' => $server['context_source'] ?? null,
                'context_server_id' => $server['context_server_id'] ?? null,
                'has_endpoint_url' => is_string($server['endpoint_url'] ?? null) && trim((string) $server['endpoint_url']) !== '',
                'has_handler' => is_string($server['handler'] ?? null) && trim((string) $server['handler']) !== '',
            ];
        })->values()->all();

        return $this->ok([
            'count' => count($items),
            'servers' => $items,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
