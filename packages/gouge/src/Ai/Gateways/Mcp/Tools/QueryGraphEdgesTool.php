<?php

namespace Webong\Gouge\Ai\Gateways\Mcp\Tools;

use App\Support\Graph\GraphEdgeExplorer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\Gouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Query semantic graph edges connected to a space, thread, or post, with optional traversal depth.')]
class QueryGraphEdgesTool extends Tool
{
    public function __construct(protected GraphEdgeExplorer $graphEdgeExplorer) {}

    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'node_type' => ['required', 'string', 'in:space,thread,post'],
            'node_id' => ['required', 'string'],
            'direction' => ['nullable', 'string', 'in:outgoing,incoming,both'],
            'edge_type' => ['nullable', 'string', 'max:100', 'not_in:'.implode(',', GraphEdgeExplorer::ReservedEdgeTypes)],
            'target_type' => ['nullable', 'string', 'in:space,thread,post'],
            'depth' => ['nullable', 'integer', 'min:1', 'max:5'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $payloads->actor($request);
        $node = $payloads->resolveGraphNode(
            $actor,
            (string) $validated['node_type'],
            (string) $validated['node_id'],
        );
        $direction = (string) ($validated['direction'] ?? GraphEdgeExplorer::DirectionOutgoing);
        $depth = (int) ($validated['depth'] ?? 1);
        $edges = $this->graphEdgeExplorer->explore(
            root: $node,
            direction: $direction,
            edgeType: is_string($validated['edge_type'] ?? null) ? (string) $validated['edge_type'] : null,
            targetType: is_string($validated['target_type'] ?? null) ? (string) $validated['target_type'] : null,
            depth: $depth,
            limit: (int) ($validated['limit'] ?? 25),
        );

        $nodes = $edges
            ->flatMap(fn (array $edge): array => [$edge['source'], $edge['target']])
            ->prepend($node)
            ->unique(fn ($relatedNode): string => sprintf('%s:%s', $relatedNode->getMorphClass(), $relatedNode->getKey()))
            ->values()
            ->map(fn ($relatedNode): array => $payloads->mapGraphNode($relatedNode))
            ->all();

        return Response::json([
            'root' => $payloads->mapGraphNode($node),
            'edges' => $edges
                ->map(fn (array $edge): array => $payloads->mapGraphEdge($edge))
                ->all(),
            'nodes' => $nodes,
            'meta' => [
                'direction' => $direction,
                'depth' => $depth,
                'edge_type' => $validated['edge_type'] ?? null,
                'target_type' => $validated['target_type'] ?? null,
                'edge_count' => $edges->count(),
                'node_count' => count($nodes),
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_type' => $schema->string()->description('The root node type.')->enum('space', 'thread', 'post')->required(),
            'node_id' => $schema->string()->description('The root node public ID.')->required(),
            'direction' => $schema->string()->description('Which edges to traverse relative to the root.')->enum('outgoing', 'incoming', 'both')->default('outgoing'),
            'edge_type' => $schema->string()
                ->description('Optional open-ended semantic edge label filter.'),
            'target_type' => $schema->string()->description('Optional target node type filter.')->enum('space', 'thread', 'post'),
            'depth' => $schema->integer()->description('Traversal depth from the root node.')->default(1),
            'limit' => $schema->integer()->description('Maximum number of edges to return.')->default(25),
        ];
    }
}
