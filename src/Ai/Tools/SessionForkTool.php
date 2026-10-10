<?php

namespace Webong\Gouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\Gouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\Gouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\Gouge\Models\Server\ThreadActorSession;

class SessionForkTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Fork the current actor session into a new AI conversation id and optionally make it active.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $session = $this->latestSessionForActor();

        if (! $session) {
            return $this->error('No session exists for this actor in this thread.');
        }

        $newConversationId = (string) Str::uuid7();
        $activate = (bool) ($request['activate'] ?? true);

        DB::table('agent_conversations')->insert([
            'id' => $newConversationId,
            'participant_type' => $this->actor->getMorphClass(),
            'participant_id' => $this->actor->id,
            'title' => 'Forked conversation for thread '.$this->thread->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($activate) {
            $session->forceFill([
                'conversation_id' => $newConversationId,
                'last_used_at' => now(),
                'state' => array_merge((array) $session->state, [
                    'mode' => ConversationPersistenceResolver::ThreadCompletion,
                    'forked_from_conversation_id' => $session->conversation_id,
                ]),
            ])->save();
        }

        return $this->ok([
            'thread_id' => $this->thread->id,
            'session_id' => $session->id,
            'forked_conversation_id' => $newConversationId,
            'previous_conversation_id' => $session->conversation_id,
            'activated' => $activate,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'activate' => $schema->boolean(),
        ];
    }

    protected function latestSessionForActor(): ?ThreadActorSession
    {
        return ThreadActorSession::query()
            ->where('thread_id', $this->thread->id)
            ->where('user_id', $this->actor->id)
            ->latest('updated_at')
            ->first();
    }
}
