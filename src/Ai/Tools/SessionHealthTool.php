<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\LaravelGouge\Models\Server\ThreadActorSession;

class SessionHealthTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Scan thread actor sessions and report broken conversation links or stale mappings.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $limit = max(1, min(200, (int) ($request['limit'] ?? 100)));

        $sessions = ThreadActorSession::query()
            ->where('thread_id', $this->thread->id)
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        $issues = [];

        foreach ($sessions as $session) {
            if (! $session->conversation_id) {
                $issues[] = [
                    'session_id' => $session->id,
                    'severity' => 'info',
                    'issue' => 'No conversation_id assigned.',
                ];

                continue;
            }

            $exists = DB::table('agent_conversations')
                ->where('id', $session->conversation_id)
                ->exists();

            if (! $exists) {
                $issues[] = [
                    'session_id' => $session->id,
                    'severity' => 'warning',
                    'issue' => 'conversation_id reference missing in agent_conversations.',
                    'conversation_id' => $session->conversation_id,
                ];
            }
        }

        return $this->ok([
            'thread_id' => $this->thread->id,
            'checked' => $sessions->count(),
            'issue_count' => count($issues),
            'issues' => $issues,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer(),
        ];
    }
}
