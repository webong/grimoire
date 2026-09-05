<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\LaravelGouge\Models\Server\ThreadActorSession;

class ConversationAuditTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
        protected ConversationPersistenceResolver $resolver = new ConversationPersistenceResolver,
    ) {}

    public function description(): Stringable|string
    {
        return 'Audit effective conversation mode, active session mapping, and context source counts for this thread/user.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $requestedMode = ConversationPersistenceResolver::normalizeMode($request['mode'] ?? null);
        $effectiveMode = $this->resolver->mode($requestedMode);

        $session = ThreadActorSession::query()
            ->where('thread_id', $this->thread->id)
            ->where('user_id', $this->actor->id)
            ->latest('updated_at')
            ->first();

        $conversationId = $session?->conversation_id;

        $conversationMessageCount = $conversationId
            ? (int) DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count()
            : 0;

        $threadMessageCount = (int) $this->thread->messages()->count();

        return $this->ok([
            'thread_id' => $this->thread->id,
            'actor_user_id' => $this->actor->id,
            'requested_mode' => $requestedMode,
            'effective_mode' => $effectiveMode,
            'session' => $session ? [
                'id' => $session->id,
                'conversation_id' => $session->conversation_id,
                'thread_actor_id' => $session->thread_actor_id,
                'provider' => $session->provider,
                'model' => $session->model,
                'last_used_at' => $session->last_used_at,
            ] : null,
            'context_counts' => [
                'thread_messages' => $threadMessageCount,
                'conversation_messages' => $conversationMessageCount,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string(),
        ];
    }
}
