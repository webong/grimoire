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

class PrivacyGuardTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Validate that access to a target user session in this thread is allowed and non-leaking.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $targetUserId = (int) ($request['target_user_id'] ?? 0);

        if ($targetUserId <= 0) {
            return $this->error('target_user_id is required.');
        }

        $allowed = $targetUserId === $this->actor->id;

        $targetSessionExists = ThreadActorSession::query()
            ->where('thread_id', $this->thread->id)
            ->where('user_id', $targetUserId)
            ->exists();

        return $this->ok([
            'thread_id' => $this->thread->id,
            'actor_user_id' => $this->actor->id,
            'target_user_id' => $targetUserId,
            'target_session_exists' => $targetSessionExists,
            'allowed' => $allowed,
            'policy' => $allowed
                ? 'Self-session access allowed.'
                : 'Cross-user session access blocked by default policy.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'target_user_id' => $schema->integer(),
        ];
    }
}
