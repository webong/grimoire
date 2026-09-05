<?php

namespace Webong\LaravelGouge\Notifications\Support;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Illuminate\Notifications\Notification;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Features\Actions\Chat\EnqueueThreadPromptOutbox;
use Webong\LaravelGouge\Features\Actions\Chat\ResolveActiveThreadPresenters;
use Webong\LaravelGouge\Support\AgentActors;
use Webong\LaravelGouge\Support\AgentEvents;

class ThreadPromptTransport
{
    public function __construct(
        protected ResolveActiveThreadPresenters $resolveActiveThreadPresenters,
        protected EnqueueThreadPromptOutbox $enqueueThreadPromptOutbox,
    ) {}

    public function send(object $notifiable, Notification $notification, string $conversationPersistenceMode): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        $payload = method_exists($notification, 'toPromptTransport')
            ? $notification->toPromptTransport($notifiable)
            : $this->payloadFromInboxNotification($notification, $notifiable);

        if (! is_array($payload)) {
            return;
        }

        $thread = $payload['thread'] ?? null;
        $message = $payload['message'] ?? null;

        if (! $thread instanceof Thread || ! $message instanceof Post) {
            return;
        }

        $resolvedMode = ConversationPersistenceResolver::normalizeMode($conversationPersistenceMode);
        $transport = $this->transportNameForMode($resolvedMode);
        $presenter = $this->resolveActiveThreadPresenters->execute($thread)->first();

        if (! $presenter instanceof ThreadActor) {
            $this->recordEvent(
                thread: $thread,
                message: $message,
                recipient: $notifiable,
                notification: $notification,
                transport: $transport,
                state: ThreadEvent::StateFailed,
                eventType: "orchestration.notification.{$transport}_missing_presenter",
                payload: [
                    'reason' => 'missing_presenter',
                    'conversation_persistence' => $resolvedMode,
                ],
            );

            return;
        }

        $actorKey = $presenter->actorName() ?: AgentActors::Coordinator;
        $existingStatus = data_get($message->meta, "invocations.{$actorKey}.status");

        if (is_string($existingStatus) && trim($existingStatus) !== '') {
            $this->recordEvent(
                thread: $thread,
                message: $message,
                recipient: $notifiable,
                notification: $notification,
                transport: $transport,
                state: ThreadEvent::StateReceived,
                eventType: "orchestration.notification.{$transport}_skipped",
                payload: [
                    'reason' => 'existing_invocation',
                    'conversation_persistence' => $resolvedMode,
                    'presenter_actor_id' => $presenter->id,
                    'presenter_actor_key' => $actorKey,
                    'existing_status' => $existingStatus,
                ],
                threadActor: $presenter,
            );

            return;
        }

        $outbox = $this->enqueueThreadPromptOutbox->execute(
            thread: $thread,
            post: $message,
            recipient: $notifiable,
            threadActor: $presenter,
            conversationPersistenceMode: $resolvedMode,
        );

        if (! $outbox->wasRecentlyCreated) {
            $this->recordEvent(
                thread: $thread,
                message: $message,
                recipient: $notifiable,
                notification: $notification,
                transport: $transport,
                state: ThreadEvent::StateReceived,
                eventType: "orchestration.notification.{$transport}_skipped",
                payload: [
                    'reason' => 'existing_outbox',
                    'conversation_persistence' => $resolvedMode,
                    'presenter_actor_id' => $presenter->id,
                    'presenter_actor_key' => $actorKey,
                    'outbox_id' => $outbox->id,
                    'outbox_status' => $outbox->status,
                ],
                threadActor: $presenter,
            );

            return;
        }

        $this->recordEvent(
            thread: $thread,
            message: $message,
            recipient: $notifiable,
            notification: $notification,
            transport: $transport,
            state: ThreadEvent::StateRequested,
            eventType: "orchestration.notification.{$transport}_requested",
            payload: [
                'reason' => 'outbox_enqueued',
                'conversation_persistence' => $resolvedMode,
                'presenter_actor_id' => $presenter->id,
                'presenter_actor_key' => $actorKey,
                'outbox_id' => $outbox->id,
                'outbox_status' => $outbox->status,
                'outbox_protocol' => $outbox->protocol,
            ],
            threadActor: $presenter,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function payloadFromInboxNotification(Notification $notification, User $notifiable): ?array
    {
        if (! method_exists($notification, 'toInbox')) {
            return null;
        }

        $message = $notification->toInbox($notifiable);
        if (! $message instanceof Post) {
            return null;
        }

        $thread = $message->postable;
        if (! $thread instanceof Thread) {
            return null;
        }

        return [
            'thread' => $thread,
            'message' => $message,
        ];
    }

    protected function transportNameForMode(?string $conversationPersistenceMode): string
    {
        return match ($conversationPersistenceMode) {
            ConversationPersistenceResolver::ThreadCompletion => ConversationTransportRouter::Completion,
            default => ConversationTransportRouter::Continuation,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function recordEvent(
        Thread $thread,
        Post $message,
        User $recipient,
        Notification $notification,
        string $transport,
        string $state,
        string $eventType,
        array $payload,
        ?ThreadActor $threadActor = null,
    ): void {
        $thread->events()->create([
            'thread_actor_id' => $threadActor?->id,
            'post_id' => $message->id,
            'event_key' => "notification:{$transport}:".$recipient->getKey(),
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::Orchestration,
            'operation' => "notification.space.{$transport}",
            'state' => $state,
            'event_type' => $eventType,
            'severity' => $state === ThreadEvent::StateFailed ? 'medium' : 'low',
            'payload' => [
                'notification' => $notification::class,
                'recipient_user_id' => $recipient->id,
                'recipient_user_uuid' => $recipient->uuid,
                'recipient_user_type' => $recipient->type,
                'post_id' => $message->id,
                'post_ulid' => $message->ulid,
                'thread_uuid' => $thread->uuid,
                ...$payload,
            ],
        ]);
    }
}
