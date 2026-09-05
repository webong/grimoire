<?php

namespace Webong\LaravelGouge\Ai\Gateways\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('List spaces the authenticated actor can access.')]
class ListSpacesTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $actor = $payloads->actor($request);
        $limit = max(1, min(25, (int) $request->integer('limit', 10)));
        $spaces = $payloads->visibleSpaces($actor, $limit)
            ->map(fn ($space): array => $payloads->mapSpace($space))
            ->all();

        return Response::json([
            'spaces' => $spaces,
            'count' => count($spaces),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of spaces to return.')->default(10),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'spaces' => $schema->array(),
            'count' => $schema->integer(),
        ];
    }
}
