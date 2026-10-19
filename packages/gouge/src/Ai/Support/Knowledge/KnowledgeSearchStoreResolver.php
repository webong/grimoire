<?php

namespace Webong\Gouge\Ai\Support\Knowledge;

use App\Models\Server\Space;
use App\Models\Server\Thread;
use Webong\Gouge\Ai\Support\ThreadContextResolver;

class KnowledgeSearchStoreResolver
{
    public function __construct(
        protected ThreadContextResolver $threadContextResolver = new ThreadContextResolver,
    ) {}

    /**
     * @return list<string>
     */
    public function resolveExternalStoreIds(Thread $thread): array
    {
        $threadStoreIds = $thread->stores()
            ->whereNotNull('external_store_id')
            ->where('status', 'active')
            ->pluck('external_store_id')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        $space = $this->threadContextResolver->resolveSpace($thread);
        $channelStoreIds = $space instanceof Space
            ? $space->stores()
                ->whereNotNull('external_store_id')
                ->where('status', 'active')
                ->pluck('external_store_id')
                ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
                ->values()
                ->all()
            : [];

        return collect()
            ->merge($threadStoreIds)
            ->merge($channelStoreIds)
            ->unique()
            ->values()
            ->all();
    }
}
