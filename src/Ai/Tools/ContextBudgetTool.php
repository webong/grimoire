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

class ContextBudgetTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Estimate token budget impact for thread-continuation versus thread-completion context sources.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $limit = max(1, min(200, (int) ($request['limit'] ?? 50)));

        $threadText = $this->thread->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($message): string => (string) $message->text)
            ->implode("\n");

        $session = ThreadActorSession::query()
            ->where('thread_id', $this->thread->id)
            ->where('user_id', $this->actor->id)
            ->latest('updated_at')
            ->first();

        $conversationText = '';

        if ($session?->conversation_id) {
            $conversationText = DB::table('agent_conversation_messages')
                ->where('conversation_id', $session->conversation_id)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (object $message): string => (string) ($message->content ?? ''))
                ->implode("\n");
        }

        $threadTokens = $this->estimateTokens($threadText);
        $conversationTokens = $this->estimateTokens($conversationText);

        $recommended = $conversationTokens <= $threadTokens
            ? ConversationPersistenceResolver::ThreadCompletion
            : ConversationPersistenceResolver::ThreadContinuation;

        return $this->ok([
            'thread_id' => $this->thread->id,
            'conversation_id' => $session?->conversation_id,
            'estimated_tokens' => [
                'thread_continuation' => $threadTokens,
                'thread_completion' => $conversationTokens,
            ],
            'recommended_mode' => $recommended,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer(),
        ];
    }

    protected function estimateTokens(string $text): int
    {
        $chars = mb_strlen($text);

        return (int) ceil($chars / 4);
    }
}
