<?php

namespace Webong\LaravelGouge\Runtime;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Webong\LaravelGouge\Support\AgentActors;

final readonly class AgentTurn
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $threadId,
        public string $threadUuid,
        public int $postId,
        public string $postUlid,
        public int $userId,
        public int $threadActorId,
        public string $actorKey,
        public string $prompt,
        public string $broadcastChannel,
        public ?string $conversationPersistenceMode = null,
        public ?string $providerChannelId = null,
        public array $metadata = [],
    ) {}

    public static function forPrompt(
        Thread $thread,
        Post $post,
        User $user,
        ThreadActor $threadActor,
        string $broadcastChannel,
        ?string $conversationPersistenceMode = null,
    ): self {
        $actorKey = $threadActor->actorName();

        return new self(
            threadId: (int) $thread->getKey(),
            threadUuid: (string) $thread->uuid,
            postId: (int) $post->getKey(),
            postUlid: (string) $post->ulid,
            userId: (int) $user->getKey(),
            threadActorId: (int) $threadActor->getKey(),
            actorKey: is_string($actorKey) && $actorKey !== '' ? $actorKey : AgentActors::Coordinator,
            prompt: is_string($post->text) ? $post->text : '',
            broadcastChannel: $broadcastChannel,
            conversationPersistenceMode: $conversationPersistenceMode,
            providerChannelId: self::trimmedString(data_get($post->meta, 'post_invocation.provider_channel_id')),
            metadata: is_array($post->meta) ? $post->meta : [],
        );
    }

    public function localCorrelationId(): string
    {
        return hash('sha256', implode(':', [
            'figurate',
            $this->threadUuid,
            $this->postUlid,
            $this->actorKey,
        ]));
    }

    protected static function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
