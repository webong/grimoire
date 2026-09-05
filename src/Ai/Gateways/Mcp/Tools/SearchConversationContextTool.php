<?php

namespace Webong\LaravelGouge\Ai\Gateways\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Search messages, posts, and linked store documents in Figurate context.')]
class SearchConversationContextTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'query' => ['required', 'string'],
            'space_id' => ['nullable', 'string'],
            'thread_id' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $actor = $payloads->actor($request);
        $space = is_string($validated['space_id'] ?? null)
            ? $payloads->resolveSpace($actor, (string) $validated['space_id'])
            : null;
        $thread = is_string($validated['thread_id'] ?? null)
            ? $payloads->resolveThread($actor, (string) $validated['thread_id'])
            : null;

        return Response::json([
            'results' => $payloads->searchContext(
                actor: $actor,
                query: (string) $validated['query'],
                space: $space,
                thread: $thread,
                limit: (int) ($validated['limit'] ?? 10),
            ),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The search text.')->required(),
            'space_id' => $schema->string()->description('Optional space UUID used to scope the search.'),
            'thread_id' => $schema->string()->description('Optional thread UUID used to scope the search.'),
            'limit' => $schema->integer()->description('Maximum number of matches to return.')->default(10),
        ];
    }
}
