<?php

namespace Webong\Gouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\Gouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\Gouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\Gouge\Models\Server\ThreadActorSession;

class ModePolicyTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Read or set preferred conversation persistence mode in the actor session state for this thread.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $mode = ConversationPersistenceResolver::normalizeMode($request['mode'] ?? null);

        $session = ThreadActorSession::query()
            ->where('thread_id', $this->thread->id)
            ->where('user_id', $this->actor->id)
            ->latest('updated_at')
            ->first();

        if (! $session) {
            return $this->error('No session available to attach mode policy.');
        }

        $state = (array) $session->state;

        if ($mode !== null) {
            $state['preferred_mode'] = $mode;

            $session->forceFill([
                'state' => $state,
                'last_used_at' => now(),
            ])->save();
        }

        return $this->ok([
            'thread_id' => $this->thread->id,
            'session_id' => $session->id,
            'preferred_mode' => $state['preferred_mode'] ?? null,
            'updated' => $mode !== null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string(),
        ];
    }
}
