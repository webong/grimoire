<?php

namespace Webong\Gouge\Ai\Support\Observer;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Webong\Gouge\Ai\Support\Observer\Contracts\ObserverSkill;
use Webong\Gouge\Ai\Tools\SafetyGuardObserver;
use Webong\Gouge\Repositories\Ai\ObserverSkillRepository;

class ObserverRegistry
{
    public function __construct(
        protected ObserverSkillRepository $observerSkillRepository,
    ) {}

    public function resolve(
        ThreadActor $threadActor,
        Thread $thread,
        Post $message,
    ): ?ObserverSkill {
        $skill = $this->observerSkillRepository->resolve($threadActor);

        if (! is_array($skill)) {
            return null;
        }

        return match (($skill['observer'] ?? null)) {
            'safety_guard' => new SafetyGuardObserver(
                thread: $thread,
                message: $message,
                threadActor: $threadActor,
                skill: $skill,
            ),
            default => null,
        };
    }
}
