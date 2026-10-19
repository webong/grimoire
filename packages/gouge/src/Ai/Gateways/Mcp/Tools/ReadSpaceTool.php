<?php

namespace Webong\Gouge\Ai\Gateways\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\Gouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Read a space and include recent threads and posts.')]
class ReadSpaceTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'space_id' => ['required', 'string'],
            'thread_limit' => ['nullable', 'integer', 'min:1', 'max:25'],
            'post_limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $actor = $payloads->actor($request);
        $space = $payloads->resolveSpace($actor, (string) $validated['space_id']);
        $threadLimit = (int) ($validated['thread_limit'] ?? 10);
        $postLimit = (int) ($validated['post_limit'] ?? 10);

        return Response::json([
            'space' => $payloads->mapSpace($space),
            'threads' => $space->conversationThreads()
                ->sortByDesc('id')
                ->take($threadLimit)
                ->values()
                ->map(fn ($thread): array => $payloads->mapThread($thread))
                ->all(),
            'posts' => $space->conversationPosts()
                ->sortByDesc('id')
                ->take($postLimit)
                ->values()
                ->map(fn ($post): array => $payloads->mapPost($post))
                ->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'space_id' => $schema->string()->description('The space UUID.')->required(),
            'thread_limit' => $schema->integer()->description('Maximum number of threads to include.')->default(10),
            'post_limit' => $schema->integer()->description('Maximum number of posts to include.')->default(10),
        ];
    }
}
