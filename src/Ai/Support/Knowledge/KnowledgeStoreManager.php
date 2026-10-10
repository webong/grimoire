<?php

namespace Webong\Gouge\Ai\Support\Knowledge;

use App\Models\Server\Space;
use App\Models\Server\Store;
use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Support\Arr;
use Laravel\Ai\Stores as AiStores;
use Throwable;

class KnowledgeStoreManager
{
    public function forThread(Thread $thread, User $actor, string $scope = 'thread_context'): Store
    {
        $store = $thread->stores()
            ->wherePivot('scope', $scope)
            ->latest('stores.id')
            ->first();

        if (! $store instanceof Store) {
            $store = Store::query()->create([
                'uuid' => (string) str()->uuid(),
                'name' => sprintf('thread-%d-%s', $thread->id, $scope),
                'provider' => (string) config('ai.default', 'default'),
                'status' => 'active',
            ]);

            $thread->stores()->attach($store->id, [
                'scope' => $scope,
                'created_by' => $actor->id,
            ]);
        }

        $this->ensureExternalStore($store);

        return $store;
    }

    public function forSpace(Space $space, User $actor, string $scope = 'space_context'): Store
    {
        $store = $space->stores()
            ->wherePivot('scope', $scope)
            ->latest('stores.id')
            ->first();

        if (! $store instanceof Store) {
            $store = Store::query()->create([
                'uuid' => (string) str()->uuid(),
                'name' => sprintf('space-%d-%s', $space->id, $scope),
                'provider' => (string) config('ai.default', 'default'),
                'status' => 'active',
            ]);

            $space->stores()->attach($store->id, [
                'scope' => $scope,
                'created_by' => $actor->id,
            ]);
        }

        $this->ensureExternalStore($store);

        return $store;
    }

    public function ensureExternalStore(Store $store): Store
    {
        if ($store->external_store_id) {
            return $store;
        }

        try {
            $providerStore = AiStores::create(name: $store->name);

            $store->forceFill([
                'external_store_id' => $providerStore->id,
                'status' => 'active',
                'meta' => Arr::except((array) $store->meta, ['last_error']),
            ])->save();
        } catch (Throwable $exception) {
            $meta = (array) $store->meta;
            $meta['last_error'] = $exception->getMessage();

            $store->forceFill([
                'status' => 'pending_sync',
                'meta' => $meta,
            ])->save();
        }

        return $store;
    }
}
