<?php

namespace Webong\LaravelGouge\Features\Actions\Chat;

use App\Jobs\DeliverOutboxMessage;
use App\Models\Server\Outbox;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Features\Actions\Chat\Protocols\AgentPromptProtocol;
use Webong\LaravelGouge\Support\AgentActors;

class EnqueueThreadPromptOutbox
{
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

        $outbox = Outbox::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'thread_id' => $thread->id,
                'post_id' => $post->id,
                'direction' => Outbox::DirectionOutbound,
                'protocol' => AgentPromptProtocol::Key,
                'provider' => 'agent-runtime',
                'target' => $target,
                'status' => Outbox::StatusPending,
                'attempts' => 0,
                'available_at' => now(),
                'payload' => [
                    'message' => [
                        'id' => $post->id,
                        'ulid' => $post->ulid,
                        'text' => $post->text,
                        'source' => data_get($post->meta, 'source'),
                        'meta' => is_array($post->meta) ? $post->meta : [],
                        'created_at' => optional($post->created_at)?->toIso8601String(),
                    ],
                    'thread' => [
                        'id' => $thread->id,
                        'uuid' => $thread->uuid,
                    ],
                    'dispatch' => [
                        'kind' => AgentPromptProtocol::Key,
                        'recipient_user_id' => $recipient->id,
                        'recipient_user_uuid' => $recipient->uuid,
                        'thread_actor_id' => $threadActor->id,
                        'thread_actor_key' => $actorKey,
                        'broadcast_space_id' => $this->broadcastSpaceId($thread),
                        'conversation_persistence' => $resolvedConversationPersistenceMode,
                    ],
                ],
            ],
        );

        if ($outbox->wasRecentlyCreated) {
            DeliverOutboxMessage::dispatch($outbox->id);
        }

        return $outbox;
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
