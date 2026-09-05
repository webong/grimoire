<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Post;
use App\Models\Server\PostRelation;
use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;

class AutoModeSelectorTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
    ) {}

    public function description(): Stringable|string
    {
        return 'Recommend a conversation persistence mode for this thread based on participation shape and privacy risk.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $participantCount = (int) PostRelation::query()
            ->where('role', Post::RelationRoleSender)
            ->whereIn('post_id', $this->thread->messages()->select('posts.id'))
            ->distinct('relationable_id')
            ->count('relationable_id');

        $privacySensitive = (bool) ($request['privacy_sensitive'] ?? false);
        $requestedMode = ConversationPersistenceResolver::normalizeMode($request['requested_mode'] ?? null);

        $recommendedMode = $participantCount > 1 || $privacySensitive
            ? ConversationPersistenceResolver::ThreadCompletion
            : ConversationPersistenceResolver::ThreadContinuation;

        return $this->ok([
            'thread_id' => $this->thread->id,
            'thread_uuid' => $this->thread->uuid,
            'participant_count' => $participantCount,
            'privacy_sensitive' => $privacySensitive,
            'requested_mode' => $requestedMode,
            'recommended_mode' => $recommendedMode,
            'reason' => $recommendedMode === ConversationPersistenceResolver::ThreadCompletion
                ? 'Multiple participants or sensitive context detected.'
                : 'Single participant conversational continuity favored.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'requested_mode' => $schema->string(),
            'privacy_sensitive' => $schema->boolean(),
        ];
    }
}
