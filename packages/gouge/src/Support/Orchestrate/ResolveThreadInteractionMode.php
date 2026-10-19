<?php

namespace Webong\Gouge\Support\Orchestrate;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Webong\Gouge\Support\AgentActors;

class ResolveThreadInteractionMode
{
    public const ModePeer = 'peer';

    public const ModePresenter = 'presenter';

    public const ModeHybrid = 'hybrid';

    /**
     * @return array{
     *     mode: string,
     *     presenter_count: int,
     *     observer_count: int
     * }
     */
    public function __invoke(Thread $thread): array
    {
        $presenterCount = $thread->actors()
            ->where('role', AgentActors::RolePresenter)
            ->where('status', ThreadActor::StatusActive)
            ->count();

        $observerCount = $thread->actors()
            ->where('role', AgentActors::RoleObserver)
            ->where('status', ThreadActor::StatusActive)
            ->count();

        $mode = match (true) {
            $presenterCount > 0 && $observerCount > 0 => self::ModeHybrid,
            $presenterCount > 0 => self::ModePresenter,
            default => self::ModePeer,
        };

        return [
            'mode' => $mode,
            'presenter_count' => $presenterCount,
            'observer_count' => $observerCount,
        ];
    }
}
