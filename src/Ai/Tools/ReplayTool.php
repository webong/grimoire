<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\LaravelGouge\Ai\Storage\ConversationId;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;

class ReplayTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Prepare replay payload from a conversation/thread context without executing a new model run.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $mode = trim((string) ($request['mode'] ?? 'thread_completion'));
        $limit = max(1, min(100, (int) ($request['limit'] ?? 25)));

        if ($mode === 'thread_continuation') {
            $messages = $this->thread->messages()
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values()
                ->map(fn ($message): array => [
                    'id' => $message->id,
                    'role' => $message->senderable_id ? 'user' : 'assistant',
                    'content' => (string) $message->text,
                ])
                ->all();

            return $this->ok([
                'mode' => $mode,
                'thread_id' => $this->thread->id,
                'messages' => $messages,
            ]);
        }

        $conversationId = ConversationId::toStorageId((string) ($request['conversation_id'] ?? ''));

        $messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (object $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => (string) ($message->content ?? ''),
            ])
            ->all();

        return $this->ok([
            'mode' => 'thread_completion',
            'conversation_id' => $conversationId,
            'messages' => $messages,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string(),
            'conversation_id' => $schema->string(),
            'limit' => $schema->integer(),
        ];
    }
}
