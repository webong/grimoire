<?php

namespace Webong\LaravelGouge\Runtime;

final readonly class AgentRuntimeCorrelation
{
    public function __construct(
        public string $id,
        public string $runtime,
        public int $threadId,
        public string $threadUuid,
        public int $postId,
        public string $postUlid,
        public int $threadActorId,
        public string $actorKey,
        public ?string $invocationId = null,
        public ?string $conversationId = null,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'runtime' => $this->runtime,
            'thread_id' => $this->threadId,
            'thread_uuid' => $this->threadUuid,
            'post_id' => $this->postId,
            'post_ulid' => $this->postUlid,
            'thread_actor_id' => $this->threadActorId,
            'actor_key' => $this->actorKey,
            'invocation_id' => $this->invocationId,
            'conversation_id' => $this->conversationId,
        ];
    }
}
