<?php

namespace Webong\LaravelGouge\Ai\Gateways\Mcp\Tools;

use App\Models\Server\Thread;
use Gate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('List threads, optionally scoped to a space.')]
class ListThreadsTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'space_id' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $actor = $payloads->actor($request);
        $limit = (int) ($validated['limit'] ?? 10);

        if (is_string($validated['space_id'] ?? null)) {
            $space = $payloads->resolveSpace($actor, (string) $validated['space_id']);
            $threads = $space->conversationThreads()
                ->sortByDesc('id')
                ->take($limit)
                ->values();
        } else {
            $threads = Thread::query()
                ->latest('id')
                ->get()
                ->filter(fn (Thread $thread): bool => Gate::forUser($actor)->allows('view', $thread))
                ->take($limit)
                ->values();
        }

        return Response::json([
            'threads' => $threads->map(fn (Thread $thread): array => $payloads->mapThread($thread))->all(),
            'count' => $threads->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'space_id' => $schema->string()->description('Optional space UUID used to scope threads.'),
            'limit' => $schema->integer()->description('Maximum number of threads to return.')->default(10),
        ];
    }
}
