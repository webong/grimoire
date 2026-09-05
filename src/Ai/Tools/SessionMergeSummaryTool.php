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

class SessionMergeSummaryTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Merge multiple conversation sessions into a concise neutral summary for shared thread updates.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $ids = (array) ($request['conversation_ids'] ?? []);
        $limitPerConversation = max(1, min(20, (int) ($request['limit_per_conversation'] ?? 5)));

        if ($ids === []) {
            return $this->error('conversation_ids is required.');
        }

        $summaryParts = [];

        foreach ($ids as $id) {
            if (! is_string($id) || trim($id) === '') {
                continue;
            }

            $conversationId = ConversationId::toStorageId($id);

            $messages = DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversationId)
                ->orderByDesc('id')
                ->limit($limitPerConversation)
                ->get()
                ->reverse()
                ->values();

            if ($messages->isEmpty()) {
                continue;
            }

            $glimpse = $messages->map(fn (object $message): string => '['.($message->role ?? 'unknown').'] '.mb_substr((string) ($message->content ?? ''), 0, 120)
            )->all();

            $summaryParts[] = [
                'conversation_id' => $conversationId,
                'message_count' => $messages->count(),
                'glimpse' => $glimpse,
            ];
        }

        return $this->ok([
            'thread_id' => $this->thread->id,
            'conversation_count' => count($summaryParts),
            'summary' => $summaryParts,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_ids' => $schema->array()
                ->items($schema->string())
                ->min(1),
            'limit_per_conversation' => $schema->integer(),
        ];
    }
}
