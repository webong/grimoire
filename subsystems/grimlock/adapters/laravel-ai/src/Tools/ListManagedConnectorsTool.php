<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Tools;

use Grimlock\LaravelAi\Contracts\ConnectorRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;

final class ListManagedConnectorsTool implements Tool
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
    ) {}

    public function description(): Stringable|string
    {
        return 'List active Grimlock-managed MCP, A2A, ACP, HTTP, and native connectors.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $connectors = $this->connectors->available();

        return json_encode([
            'ok' => true,
            'count' => count($connectors),
            'connectors' => array_map(static fn ($connector): array => $connector->toArray(), $connectors),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
