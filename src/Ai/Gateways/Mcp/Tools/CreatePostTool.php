<?php

namespace Webong\LaravelGouge\Ai\Gateways\Mcp\Tools;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Create a durable post on a space or thread.')]
class CreatePostTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'target_type' => ['required', 'string', 'in:space,thread'],
            'target_id' => ['required', 'string'],
            'type' => ['required', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:50'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $actor = $payloads->actor($request);
        $targetType = (string) $validated['target_type'];
        $target = $targetType === 'space'
            ? $payloads->resolveUpdatableSpace($actor, (string) $validated['target_id'])
            : $payloads->resolveUpdatableThread($actor, (string) $validated['target_id']);

        $payload = is_array($validated['payload'] ?? null) ? $validated['payload'] : [];
        $meta = is_array($validated['meta'] ?? null) ? $validated['meta'] : [];

        if (is_string($validated['title'] ?? null) && trim((string) $validated['title']) !== '') {
            $payload['title'] = trim((string) $validated['title']);
        }

        if (is_string($validated['body'] ?? null) && trim((string) $validated['body']) !== '') {
            $payload['body'] = trim((string) $validated['body']);
        }

        $meta['source'] = 'mcp';
        $meta['actor_id'] = $actor->uuid ?? $actor->getKey();

        /** @var Space|Thread $target */
        $post = $target->posts()->create([
            'type' => (string) $validated['type'],
            'status' => (string) ($validated['status'] ?? 'draft'),
            'payload' => $payload,
            'meta' => $meta,
            'occurred_at' => $validated['occurred_at'] ?? now(),
        ]);

        return Response::json([
            'post' => $payloads->mapPost($post instanceof Post ? $post : $post->fresh()),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'target_type' => $schema->string()->description('Where to attach the post: space or thread.')->required(),
            'target_id' => $schema->string()->description('The target space or thread UUID.')->required(),
            'type' => $schema->string()->description('The post type, for example note.created or summary.snapshot.')->required(),
            'status' => $schema->string()->description('Optional post status.')->default('draft'),
            'title' => $schema->string()->description('Optional title copied into the post payload.'),
            'body' => $schema->string()->description('Optional body copied into the post payload.'),
            'payload' => $schema->array()->description('Optional additional payload fields.'),
            'meta' => $schema->array()->description('Optional metadata fields.'),
            'occurred_at' => $schema->string()->description('Optional ISO-8601 timestamp for the post.'),
        ];
    }
}
