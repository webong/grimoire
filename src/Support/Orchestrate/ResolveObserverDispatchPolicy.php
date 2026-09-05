<?php

namespace Webong\LaravelGouge\Support\Orchestrate;

use App\Models\Server\Thread;

class ResolveObserverDispatchPolicy
{
    public function __construct(
        protected ResolveThreadInteractionMode $resolveThreadInteractionMode,
    ) {}

    /**
     * @return array{
     *     should_dispatch: bool,
     *     status: string,
     *     interaction_mode: string,
     *     observer_count: int,
     *     presenter_count: int
     * }
     */
    public function forThread(Thread $thread): array
    {
        $interaction = ($this->resolveThreadInteractionMode)($thread);
        $observerCount = (int) $interaction['observer_count'];

        if ($observerCount === 0) {
            return [
                'should_dispatch' => false,
                'status' => 'none',
                'interaction_mode' => (string) $interaction['mode'],
                'observer_count' => $observerCount,
                'presenter_count' => (int) $interaction['presenter_count'],
            ];
        }

        return [
            'should_dispatch' => true,
            'status' => 'queued',
            'interaction_mode' => (string) $interaction['mode'],
            'observer_count' => $observerCount,
            'presenter_count' => (int) $interaction['presenter_count'],
        ];
    }
}
