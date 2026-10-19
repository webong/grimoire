<?php

namespace Webong\Gouge\Ai\Gateways\Mcp\Tools;

use App\Support\Graph\GraphEdgeExplorer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\Gouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Create a semantic graph edge between spaces, threads, and posts without changing their node hierarchy.')]
class CreateGraphEdgeTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'source_type' => ['required', 'string', 'in:space,thread,post'],
            'source_id' => ['required', 'string'],
            'target_type' => ['required', 'string', 'in:space,thread,post'],
            'target_id' => ['required', 'string'],
            'edge_type' => ['required', 'string', 'max:100', 'not_in:'.implode(',', GraphEdgeExplorer::ReservedEdgeTypes)],
            'purpose' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $payloads->actor($request);
        $source = $payloads->resolveGraphNode(
            $actor,
            (string) $validated['source_type'],
            (string) $validated['source_id'],
            true,
        );
        $target = $payloads->resolveGraphNode(
            $actor,
            (string) $validated['target_type'],
            (string) $validated['target_id'],
        );

        $relation = match (true) {
            method_exists($source, 'attachRelation') => $source->attachRelation(
                $target,
                (string) $validated['edge_type'],
                is_string($validated['purpose'] ?? null) ? (string) $validated['purpose'] : null,
            ),
            default => abort(422, 'The selected source does not support graph edges.'),
        };

        return Response::json([
            'edge' => $payloads->mapGraphEdge([
                'relation' => $relation,
                'source' => $source,
                'target' => $target,
                'direction' => 'outgoing',
                'depth' => 1,
            ]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'source_type' => $schema->string()->description('The source node type.')->enum('space', 'thread', 'post')->required(),
            'source_id' => $schema->string()->description('The source node public ID.')->required(),
            'target_type' => $schema->string()->description('The target node type.')->enum('space', 'thread', 'post')->required(),
            'target_id' => $schema->string()->description('The target node public ID.')->required(),
            'edge_type' => $schema->string()
                ->description('An open-ended semantic relationship label. Structural labels such as child_of are reserved.')
                ->required(),
            'purpose' => $schema->string()->description('Optional note describing why the edge exists.'),
        ];
    }
}
