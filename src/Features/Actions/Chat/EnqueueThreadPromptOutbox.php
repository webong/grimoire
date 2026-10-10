<?php

namespace Webong\Gouge\Features\Actions\Chat;

use App\Features\Actions\Coordination\EnqueueWorkRequest;
use App\Models\Server\Outbox;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Webong\Gouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\Gouge\Coordination\GougeBridge;
use Webong\Gouge\Support\AgentActors;

class EnqueueThreadPromptOutbox
{
    public function __construct(protected EnqueueWorkRequest $enqueueWorkRequest) {}

    public function execute(
        Thread $thread,
        Post $post,
        User $recipient,
        ThreadActor $threadActor,
        ?string $conversationPersistenceMode = null,
    ): Outbox {
        $resolvedConversationPersistenceMode = ConversationPersistenceResolver::normalizeMode($conversationPersistenceMode)
            ?? ConversationPersistenceResolver::ThreadContinuation;
        $actorKey = $threadActor->actorName() ?: AgentActors::Coordinator;
        $target = $threadActor->actorReference() ?: $actorKey;
        $idempotencyKey = $this->promptIdempotencyKey(
            post: $post,
            recipient: $recipient,
            threadActor: $threadActor,
            conversationPersistenceMode: $resolvedConversationPersistenceMode,
        );

        return $this->enqueueWorkRequest->execute(
            thread: $thread,
            post: $post,
            participant: $threadActor,
            bridge: GougeBridge::Key,
            endpoint: $target,
            action: 'respond',
            input: [
                'recipient_user_id' => $recipient->id,
                'recipient_user_uuid' => $recipient->uuid,
                'broadcast_space_id' => $this->broadcastSpaceId($thread),
                'conversation_persistence' => $resolvedConversationPersistenceMode,
            ],
            metadata: [
                'actor_key' => $actorKey,
                'message_source' => data_get($post->meta, 'source'),
            ],
            idempotencyKey: $idempotencyKey,
        );
    }

    public function promptIdempotencyKey(
        Post $post,
        User $recipient,
        ThreadActor $threadActor,
        string $conversationPersistenceMode,
    ): string {
        return sprintf(
            'prompt:%d:%d:%d:%s',
            $post->id,
            $threadActor->id,
            $recipient->id,
            $conversationPersistenceMode,
        );
    }

    public function broadcastSpaceId(Thread $thread): string
    {
        return "threads.{$thread->uuid}";
    }
}
