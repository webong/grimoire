<?php

namespace Webong\Gouge\Features\Actions\Chat;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Illuminate\Support\Collection;

class QueuePresenterReplies
{
    public function __construct(protected EnqueueThreadPromptOutbox $enqueueThreadPromptOutbox) {}

    /**
     * @param  Collection<int, ThreadActor>  $presenters
     */
    public function execute(
        Thread $thread,
        Post $userMessage,
        User $actor,
        Collection $presenters,
        string $broadcastSpaceId,
    ): void {
        $presenters->each(function (ThreadActor $presenter) use ($thread, $userMessage, $actor): void {
            $this->enqueueThreadPromptOutbox->execute(
                thread: $thread,
                post: $userMessage,
                recipient: $actor,
                threadActor: $presenter,
            );
        });
    }
}
