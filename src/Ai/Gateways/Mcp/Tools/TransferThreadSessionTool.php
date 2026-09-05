<?php

namespace Webong\LaravelGouge\Ai\Gateways\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;
use Webong\LaravelGouge\Models\Server\ThreadActorSession;

#[Description('Transfer a stored conversation session from one user to another within a thread.')]
class TransferThreadSessionTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'thread_id' => ['required', 'string'],
            'from_user_id' => ['required', 'integer', 'min:1'],
            'to_user_id' => ['required', 'integer', 'min:1'],
            'thread_actor_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $actor = $payloads->actor($request);
        $thread = $payloads->resolveUpdatableThread($actor, (string) $validated['thread_id']);

        $fromSessionQuery = ThreadActorSession::query()
            ->where('thread_id', $thread->id)
            ->where('user_id', (int) $validated['from_user_id']);

        if (is_int($validated['thread_actor_id'] ?? null)) {
            $fromSessionQuery->where('thread_actor_id', (int) $validated['thread_actor_id']);
        }

        $from = $fromSessionQuery->latest('updated_at')->first();
        abort_if(! $from instanceof ThreadActorSession || ! $from->conversation_id, 404, 'No transferable session found.');

        $to = ThreadActorSession::query()->firstOrCreate(
            [
                'thread_id' => $thread->id,
                'thread_actor_id' => $from->thread_actor_id,
                'user_id' => (int) $validated['to_user_id'],
                'provider' => $from->provider,
                'model' => $from->model,
            ],
            [
                'conversation_id' => null,
                'state' => null,
                'last_used_at' => null,
            ],
        );

        $to->forceFill([
            'conversation_id' => $from->conversation_id,
            'last_used_at' => now(),
        ])->save();

        return Response::json([
            'thread_id' => $thread->uuid,
            'from_session_id' => $from->id,
            'to_session_id' => $to->id,
            'conversation_id' => $from->conversation_id,
            'transferred' => true,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->string()->description('The thread UUID.')->required(),
            'from_user_id' => $schema->integer()->description('The source user ID.')->required(),
            'to_user_id' => $schema->integer()->description('The destination user ID.')->required(),
            'thread_actor_id' => $schema->integer()->description('Optional thread actor ID used to narrow the source session.'),
        ];
    }
}
