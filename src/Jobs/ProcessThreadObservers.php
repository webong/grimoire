<?php

namespace Webong\LaravelGouge\Jobs;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Webong\LaravelGouge\Ai\Support\Observer\Contracts\ObserverSkill;
use Webong\LaravelGouge\Ai\Support\Observer\ObserverRegistry;
use Webong\LaravelGouge\Ai\Support\Observer\ObserverResult;
use Webong\LaravelGouge\Support\AgentActors;
use Webong\LaravelGouge\Support\AgentEvents;
use Webong\LaravelGouge\Support\Orchestrate\ResolveObserverDispatchPolicy;

class ProcessThreadObservers implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $threadId,
        public int $postId,
    ) {
        $this->afterCommit();
    }

    public function handle(ObserverRegistry $registry, ResolveObserverDispatchPolicy $resolveObserverDispatchPolicy): void
    {
        $thread = Thread::query()
            ->with(['actors' => fn ($query) => $query
                ->where('role', AgentActors::RoleObserver)
                ->where('status', ThreadActor::StatusActive)
                ->orderBy('priority')])
            ->find($this->threadId);

        $message = Post::query()
            ->messageType()
            ->find($this->postId);

        if (! $thread || ! $message) {
            return;
        }

        $observerPolicy = $resolveObserverDispatchPolicy->forThread($thread);

        if (! $observerPolicy['should_dispatch']) {
            return;
        }

        $updatedMeta = $message->meta ?? [];
        $messageChanged = false;

        foreach ($thread->actors as $threadActor) {
            $observerSkill = $registry->resolve($threadActor, $thread, $message);

            if (! $observerSkill) {
                continue;
            }

            $result = $this->observeWithSkill($observerSkill);

            if (! $result) {
                continue;
            }

            $thread->events()->create([
                'thread_actor_id' => $threadActor->id,
                'post_id' => $message->id,
                'event_key' => $threadActor->actorReference(),
                'layer' => ThreadEvent::LayerExecution,
                'kind' => AgentEvents::Observer,
                'operation' => $result->eventType,
                'state' => ThreadEvent::StateCompleted,
                'event_type' => $result->eventType,
                'severity' => $result->severity,
                'payload' => $result->payload,
            ]);

            if ($result->eventType === 'moderation_flagged') {
                $updatedMeta['moderation_status'] = 'flagged';
                $updatedMeta['observer_flags'][] = $threadActor->actorReference();
                $messageChanged = true;
            }

            if (
                $result->eventType === 'message_blocked' &&
                (($threadActor->config['mode'] ?? AgentActors::ModePassive) === AgentActors::ModeEnforcing) &&
                $result->redactMessage
            ) {
                $updatedMeta['moderation_status'] = 'blocked';
                $updatedMeta['observer_flags'][] = $threadActor->actorReference();
                $message->text = '[Message removed by safety policy]';
                $messageChanged = true;
            }
        }

        if ($messageChanged) {
            $updatedMeta['observer_flags'] = array_values(array_unique($updatedMeta['observer_flags'] ?? []));

            $message->forceFill([
                'meta' => $updatedMeta,
            ])->save();
        }
    }

    protected function observeWithSkill(ObserverSkill $observerSkill): ?ObserverResult
    {
        return $observerSkill->observe();
    }
}
