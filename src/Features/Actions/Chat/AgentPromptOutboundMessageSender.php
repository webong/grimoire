<?php

namespace Webong\LaravelGouge\Features\Actions\Chat;

use App\Features\Actions\Chat\Contracts\OutboundMessageSender;
use App\Models\Server\Outbox;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Contracts\AgentRuntime;
use Webong\LaravelGouge\Features\Actions\Chat\Protocols\AgentPromptProtocol;
use Webong\LaravelGouge\Runtime\AgentTurn;

class AgentPromptOutboundMessageSender implements OutboundMessageSender
{
    public function __construct(protected AgentRuntime $agentRuntime) {}

    /**
     * @return array<string, mixed>
     */
    public function send(Outbox $outbox): array
    {
        $thread = $this->resolveThread($outbox);
        $post = $this->resolveMessage($outbox);
        $recipient = $this->resolveRecipient($outbox);
        $threadActor = $this->resolveThreadActor($outbox);

        if (
            $threadActor->thread_id !== $thread->id ||
            $post->postable_type !== $thread->getMorphClass() ||
            $post->postable_id !== $thread->getKey()
        ) {
            throw new \RuntimeException('Agent prompt outbox references mismatched thread resources.');
        }

        $conversationPersistenceMode = ConversationPersistenceResolver::normalizeMode(
            data_get($outbox->payload, 'dispatch.conversation_persistence')
        );
        $broadcastSpaceId = $this->stringValue(data_get($outbox->payload, 'dispatch.broadcast_space_id'))
            ?? "threads.{$thread->uuid}";

        $turn = AgentTurn::forPrompt(
            thread: $thread,
            post: $post,
            user: $recipient,
            threadActor: $threadActor,
            broadcastChannel: $broadcastSpaceId,
            conversationPersistenceMode: $conversationPersistenceMode,
        );

        $correlation = $this->agentRuntime->correlationFor($turn);
        $this->agentRuntime->submitTurn($turn);

        return [
            'ok' => true,
            'protocol' => AgentPromptProtocol::Key,
            'provider' => $outbox->provider ?: 'agent-runtime',
            'target' => $outbox->target,
            'delivery' => 'queued_for_agent',
            'thread_id' => $thread->id,
            'post_id' => $post->id,
            'recipient_user_id' => $recipient->id,
            'thread_actor_id' => $threadActor->id,
            'thread_actor_key' => $threadActor->actorName(),
            'conversation_persistence' => $conversationPersistenceMode,
            'correlation' => $correlation->toArray(),
        ];
    }

    protected function resolveThread(Outbox $outbox): Thread
    {
        $thread = $outbox->relationLoaded('thread') ? $outbox->thread : null;
        $thread ??= Thread::query()->find($outbox->thread_id);

        if (! $thread instanceof Thread) {
            throw new \RuntimeException('Agent prompt outbox thread could not be resolved.');
        }

        return $thread;
    }

    protected function resolveMessage(Outbox $outbox): Post
    {
        $post = $outbox->relationLoaded('post') ? $outbox->post : null;
        $post ??= $outbox->post_id ? Post::query()->find($outbox->post_id) : null;

        if (! $post instanceof Post) {
            throw new \RuntimeException('Agent prompt outbox post could not be resolved.');
        }

        return $post;
    }

    protected function resolveRecipient(Outbox $outbox): User
    {
        $recipientId = data_get($outbox->payload, 'dispatch.recipient_user_id');
        $recipient = is_numeric($recipientId) ? User::query()->find((int) $recipientId) : null;

        if (! $recipient instanceof User) {
            throw new \RuntimeException('Agent prompt outbox recipient could not be resolved.');
        }

        return $recipient;
    }

    protected function resolveThreadActor(Outbox $outbox): ThreadActor
    {
        $threadActorId = data_get($outbox->payload, 'dispatch.thread_actor_id');
        $threadActor = is_numeric($threadActorId) ? ThreadActor::query()->find((int) $threadActorId) : null;

        if (! $threadActor instanceof ThreadActor) {
            throw new \RuntimeException('Agent prompt outbox presenter could not be resolved.');
        }

        return $threadActor;
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
