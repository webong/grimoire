<?php

namespace Webong\Gouge\Features\Actions\Chat;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Webong\Gouge\Support\AgentActors;

class EnsureThreadPresenter
{
    public function __construct(protected ResolveActiveThreadPresenters $resolveActiveThreadPresenters) {}

    public function execute(Thread $thread, string $presenterActorType): void
    {
        if ($this->resolveActiveThreadPresenters->execute($thread)->isNotEmpty()) {
            return;
        }

        $thread->actors()->create([
            'actorable_type' => $presenterActorType,
            'actorable_id' => null,
            'role' => AgentActors::RolePresenter,
            'status' => ThreadActor::StatusActive,
            'priority' => 1,
            'config' => null,
        ]);
    }
}
