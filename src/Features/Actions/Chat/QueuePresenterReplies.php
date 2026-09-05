<?php

namespace Webong\LaravelGouge\Features\Actions\Chat;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Illuminate\Support\Collection;
use Webong\LaravelGouge\Contracts\AgentRuntime;
use Webong\LaravelGouge\Runtime\AgentTurn;

class QueuePresenterReplies
{
    public function __construct(protected AgentRuntime $agentRuntime) {}

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
        $presenters->each(function (ThreadActor $presenter) use ($thread, $userMessage, $actor, $broadcastSpaceId): void {
            $this->agentRuntime->submitTurn(AgentTurn::forPrompt(
                thread: $thread,
                post: $userMessage,
                user: $actor,
                threadActor: $presenter,
                broadcastChannel: $broadcastSpaceId,
            ));
        });
    }
}
