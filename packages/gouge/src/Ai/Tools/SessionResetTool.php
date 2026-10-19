<?php

namespace Webong\Gouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\Gouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\Gouge\Models\Server\ThreadActorSession;

class SessionResetTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Reset current actor session mapping for this thread without affecting other users.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $session = ThreadActorSession::query()
            ->where('thread_id', $this->thread->id)
            ->where('user_id', $this->actor->id)
            ->latest('updated_at')
            ->first();

        if (! $session) {
            return $this->error('No session found to reset.');
        }

        $conversationId = $session->conversation_id;

        $session->forceFill([
            'conversation_id' => null,
            'last_used_at' => now(),
        ])->save();

        return $this->ok([
            'thread_id' => $this->thread->id,
            'session_id' => $session->id,
            'previous_conversation_id' => $conversationId,
            'reset' => true,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
