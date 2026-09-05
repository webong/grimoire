<?php

namespace Webong\LaravelGouge\Notifications\Channels;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Illuminate\Notifications\Notification;
use Webong\LaravelGouge\Support\AgentEvents;

class ThreadCoordinationChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toCoordination')) {
            return;
        }

        $coordination = $notification->toCoordination($notifiable);

        if (! is_array($coordination)) {
            return;
        }

        $thread = $coordination['thread'] ?? null;
        $post = $coordination['message'] ?? null;
        $space = $coordination['space'] ?? null;
        $source = $coordination['source'] ?? null;

        if (! $thread instanceof Thread || ! $post instanceof Post) {
            return;
        }

        $thread->events()->create([
            'thread_actor_id' => $this->resolveThreadActorId($thread, $notifiable),
            'post_id' => $post->id,
            'event_key' => 'notification:coordination:'.$notifiable->getKey(),
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::Orchestration,
            'operation' => 'notification.space.coordination',
            'state' => ThreadEvent::StateRequested,
            'event_type' => 'orchestration.notification.coordination_requested',
            'severity' => 'low',
            'payload' => [
                'notification' => $notification::class,
                'recipient_user_id' => $notifiable->id,
                'recipient_user_uuid' => $notifiable->uuid,
                'recipient_user_type' => $notifiable->type,
                'post_id' => $post->id,
                'post_ulid' => $post->ulid,
                'thread_uuid' => $thread->uuid,
                'space_uuid' => $space instanceof Space ? $space->uuid : null,
                'source' => is_string($source) && trim($source) !== '' ? trim($source) : null,
            ],
        ]);
    }

    protected function resolveThreadActorId(Thread $thread, User $recipient): ?int
    {
        return $thread->actors()
            ->where('status', ThreadActor::StatusActive)
            ->whereMorphedTo('actorable', $recipient)
            ->value('id');
    }
}
