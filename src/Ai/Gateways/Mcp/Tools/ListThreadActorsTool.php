<?php

namespace Webong\LaravelGouge\Ai\Gateways\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('List the actors assigned to a thread.')]
class ListThreadActorsTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'thread_id' => ['required', 'string'],
        ]);

        $actor = $payloads->actor($request);
        $thread = $payloads->resolveThread($actor, (string) $validated['thread_id']);

        return Response::json([
            'actors' => $thread->actors()
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
                ->map(fn ($threadActor): array => $payloads->mapActor($threadActor))
                ->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->string()->description('The thread UUID.')->required(),
        ];
    }
}
