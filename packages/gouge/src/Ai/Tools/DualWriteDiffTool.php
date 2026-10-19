<?php

namespace Webong\Gouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\Gouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\Gouge\Models\Server\ThreadActorSession;

class DualWriteDiffTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Compare recent thread message context against session conversation context to detect dual-write drift.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $limit = max(1, min(100, (int) ($request['limit'] ?? 20)));

        $session = ThreadActorSession::query()
            ->where('thread_id', $this->thread->id)
            ->where('user_id', $this->actor->id)
            ->latest('updated_at')
            ->first();

        if (! $session || ! $session->conversation_id) {
            return $this->error('No active session conversation found for actor.');
        }

        $threadRows = $this->thread->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($message): string => trim((string) $message->text))
            ->all();

        $conversationRows = DB::table('agent_conversation_messages')
            ->where('conversation_id', $session->conversation_id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (object $message): string => trim((string) ($message->content ?? '')))
            ->all();

        $threadSignature = md5(json_encode($threadRows));
        $conversationSignature = md5(json_encode($conversationRows));

        return $this->ok([
            'thread_id' => $this->thread->id,
            'conversation_id' => $session->conversation_id,
            'limit' => $limit,
            'thread_count' => count($threadRows),
            'conversation_count' => count($conversationRows),
            'signatures' => [
                'thread' => $threadSignature,
                'conversation' => $conversationSignature,
            ],
            'matches' => $threadSignature === $conversationSignature,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer(),
        ];
    }
}
