<?php

namespace Webong\Gouge\Ai\Gateways\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\Gouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Read a thread with recent messages, posts, and actors.')]
class ReadThreadTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'thread_id' => ['required', 'string'],
            'message_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'post_limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $actor = $payloads->actor($request);
        $thread = $payloads->resolveThread($actor, (string) $validated['thread_id']);
        $messageLimit = (int) ($validated['message_limit'] ?? 20);
        $postLimit = (int) ($validated['post_limit'] ?? 10);

        return Response::json([
            'thread' => $payloads->mapThread($thread),
            'messages' => $thread->messages()
                ->latest('id')
                ->take($messageLimit)
                ->get()
                ->reverse()
                ->values()
                ->map(fn ($message): array => $payloads->mapMessage($message))
                ->all(),
            'posts' => $thread->posts()
                ->latest('id')
                ->take($postLimit)
                ->get()
                ->map(fn ($post): array => $payloads->mapPost($post))
                ->all(),
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
            'message_limit' => $schema->integer()->description('Maximum number of messages to include.')->default(20),
            'post_limit' => $schema->integer()->description('Maximum number of posts to include.')->default(10),
        ];
    }
}
