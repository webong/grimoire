<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\LaravelGouge\Models\Server\ThreadActorSession;

class SessionTransferTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Transfer a conversation mapping from one user session to another within the same thread actor.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $fromUserId = (int) ($request['from_user_id'] ?? 0);
        $toUserId = (int) ($request['to_user_id'] ?? 0);

        if ($fromUserId <= 0 || $toUserId <= 0) {
            return $this->error('from_user_id and to_user_id are required.');
        }

        $from = ThreadActorSession::query()
            ->where('thread_id', $this->thread->id)
            ->where('user_id', $fromUserId)
            ->latest('updated_at')
            ->first();

        if (! $from || ! $from->conversation_id) {
            return $this->error('Source session has no transferable conversation.');
        }

        $to = ThreadActorSession::query()->firstOrCreate(
            [
                'thread_id' => $this->thread->id,
                'thread_actor_id' => $from->thread_actor_id,
                'user_id' => $toUserId,
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

        return $this->ok([
            'thread_id' => $this->thread->id,
            'from_session_id' => $from->id,
            'to_session_id' => $to->id,
            'conversation_id' => $from->conversation_id,
            'transferred' => true,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from_user_id' => $schema->integer(),
            'to_user_id' => $schema->integer(),
        ];
    }
}
