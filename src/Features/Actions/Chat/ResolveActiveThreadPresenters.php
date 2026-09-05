<?php

namespace Webong\LaravelGouge\Features\Actions\Chat;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Illuminate\Support\Collection;
use Webong\LaravelGouge\Support\AgentActors;

class ResolveActiveThreadPresenters
{
    /**
     * @return Collection<int, ThreadActor>
     */
    public function execute(Thread $thread): Collection
    {
        return $thread->actors()
            ->where('role', AgentActors::RolePresenter)
            ->where('status', ThreadActor::StatusActive)
            ->orderBy('priority')
            ->get();
    }
}
