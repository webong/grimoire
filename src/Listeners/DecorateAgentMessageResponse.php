<?php

namespace Webong\Gouge\Listeners;

use App\Events\Server\Chat\PreparingMessageResponse;
use App\Models\Server\Post;
use App\Models\Server\ThreadActor;
use Illuminate\Support\Collection;
use Webong\Gouge\Features\Actions\Chat\FindAssistantRepliesForMessage;
use Webong\Gouge\Features\Actions\Chat\ResolveActiveThreadPresenters;
use Webong\Gouge\Support\Orchestrate\ResolveObserverDispatchPolicy;

class DecorateAgentMessageResponse
{
    public function __construct(
        protected ResolveActiveThreadPresenters $resolveActiveThreadPresenters,
        protected ResolveObserverDispatchPolicy $resolveObserverDispatchPolicy,
        protected FindAssistantRepliesForMessage $findAssistantRepliesForMessage,
    ) {}

    public function handle(PreparingMessageResponse $event): void
    {
        $presenters = $this->resolveActiveThreadPresenters->execute($event->thread);
        $observerPolicy = $this->resolveObserverDispatchPolicy->forThread($event->thread);
        $expectedPresenterReplyCount = $this->expectedPresenterReplyCount($presenters);

        $event->body['interaction_mode'] = $observerPolicy['interaction_mode'];
        $event->body['observer_status'] = $observerPolicy['status'];

        if ($event->duplicate) {
            $assistantMessages = $this->findAssistantRepliesForMessage->execute(
                $event->thread,
                $event->post,
                $presenters,
            );
            $firstAssistantMessage = $assistantMessages->first();

            $event->body['text'] = $firstAssistantMessage?->text;
            $event->body['assistant_post_id'] = $firstAssistantMessage?->ulid;
            $event->body['assistant_posts'] = $assistantMessages
                ->map(fn (Post $message): array => [
                    'id' => $message->ulid,
                    'actor_key' => data_get($message->meta, 'actor_key'),
                    'text' => $message->text,
                    'created_at' => optional($message->created_at)?->toIso8601String(),
                ])
                ->values()
                ->all();
            $event->body['pending'] = $assistantMessages->count() < $expectedPresenterReplyCount;
            $event->body['pending_presenters'] = max(
                $expectedPresenterReplyCount - $assistantMessages->count(),
                0,
            );

            return;
        }

        $hasPresenters = $presenters->isNotEmpty();
        $event->status = $hasPresenters ? 202 : $event->status;
        $event->body['message'] = $hasPresenters ? 'Agent response queued.' : $event->body['message'];
        $event->body['assistant_post_id'] = null;
        $event->body['pending_presenters'] = $hasPresenters ? $expectedPresenterReplyCount : 0;
        $event->body['pending'] = $hasPresenters;
    }

    /**
     * @param  Collection<int, ThreadActor>  $presenters
     */
    protected function expectedPresenterReplyCount(Collection $presenters): int
    {
        return $presenters
            ->map(fn (ThreadActor $presenter): string => $presenter->actorName())
            ->filter(fn (?string $actorKey): bool => is_string($actorKey) && $actorKey !== '')
            ->unique()
            ->count();
    }
}
