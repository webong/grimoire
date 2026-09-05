<?php

namespace Webong\LaravelGouge\Features\Actions\Chat;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Illuminate\Support\Collection;

class FindAssistantRepliesForMessage
{
    /**
     * @param  Collection<int, ThreadActor>  $activePresenters
     * @return Collection<int, Post>
     */
    public function execute(Thread $thread, Post $userMessage, Collection $activePresenters): Collection
    {
        if ($activePresenters->isEmpty()) {
            return collect();
        }

        $presenterKeys = $activePresenters
            ->map(fn (ThreadActor $presenter): string => $presenter->actorName())
            ->filter(fn (?string $actorKey): bool => is_string($actorKey) && $actorKey !== '')
            ->values();

        if ($presenterKeys->isEmpty()) {
            return collect();
        }

        return Post::query()
            ->forThread($thread)
            ->withoutSender()
            ->where('meta->source', 'agent_response')
            ->where('meta->in_reply_to_post_id', $userMessage->id)
            ->whereIn('meta->actor_key', $presenterKeys->all())
            ->orderBy('id')
            ->get();
    }
}
