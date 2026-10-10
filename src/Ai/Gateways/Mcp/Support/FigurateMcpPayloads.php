<?php

namespace Webong\Gouge\Ai\Gateways\Mcp\Support;

use App\Models\Server\Post;
use App\Models\Server\PostRelation;
use App\Models\Server\Space;
use App\Models\Server\SpaceRelation;
use App\Models\Server\Store;
use App\Models\Server\StoreDocument;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadRelation;
use App\Models\Server\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Webong\Gouge\Support\AgentActors;

class FigurateMcpPayloads
{
    public function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401, 'Authentication is required.');

        return $actor;
    }

    /**
     * @return Collection<int, Space>
     */
    public function visibleSpaces(User $actor, int $limit = 10): Collection
    {
        Gate::forUser($actor)->authorize('viewAny', Space::class);

        return Space::query()
            ->latest('id')
            ->get()
            ->filter(fn (Space $space): bool => Gate::forUser($actor)->allows('view', $space))
            ->take($this->clampLimit($limit))
            ->values();
    }

    public function resolveSpace(User $actor, string $spaceUuid): Space
    {
        $space = Space::query()
            ->where('uuid', $spaceUuid)
            ->firstOrFail();

        Gate::forUser($actor)->authorize('view', $space);

        return $space;
    }

    public function resolveUpdatableSpace(User $actor, string $spaceUuid): Space
    {
        $space = $this->resolveSpace($actor, $spaceUuid);

        Gate::forUser($actor)->authorize('update', $space);

        return $space;
    }

    public function resolveThread(User $actor, string $threadUuid): Thread
    {
        $thread = Thread::query()
            ->where('uuid', $threadUuid)
            ->firstOrFail();

        Gate::forUser($actor)->authorize('view', $thread);

        return $thread;
    }

    public function resolveUpdatableThread(User $actor, string $threadUuid): Thread
    {
        $thread = $this->resolveThread($actor, $threadUuid);

        Gate::forUser($actor)->authorize('update', $thread);

        return $thread;
    }

    public function resolveGraphNode(User $actor, string $nodeType, string $nodeId, bool $updatable = false): Model
    {
        return match ($nodeType) {
            'space' => $updatable
                ? $this->resolveUpdatableSpace($actor, $nodeId)
                : $this->resolveSpace($actor, $nodeId),
            'thread' => $updatable
                ? $this->resolveUpdatableThread($actor, $nodeId)
                : $this->resolveThread($actor, $nodeId),
            'post' => $updatable
                ? $this->resolveUpdatablePost($actor, $nodeId)
                : $this->resolvePost($actor, $nodeId),
            default => abort(422, 'Unsupported graph node type.'),
        };
    }

    public function resolvePost(User $actor, string $postUlid): Post
    {
        $post = Post::query()
            ->where('ulid', $postUlid)
            ->firstOrFail();

        Gate::forUser($actor)->authorize('view', $post);

        return $post;
    }

    public function resolveUpdatablePost(User $actor, string $postUlid): Post
    {
        $post = $this->resolvePost($actor, $postUlid);

        Gate::forUser($actor)->authorize('update', $post);

        return $post;
    }

    public function mapSpace(Space $space): array
    {
        return [
            'id' => $space->uuid,
            'status' => $space->status,
            'thread_count' => $space->conversationThreadIds()->count(),
            'post_count' => $space->conversationPosts()->count(),
            'latest_message_at' => optional($space->latestConversationMessage()?->created_at)?->toIso8601String(),
            'created_at' => optional($space->created_at)?->toIso8601String(),
            'updated_at' => optional($space->updated_at)?->toIso8601String(),
        ];
    }

    public function mapThread(Thread $thread): array
    {
        return [
            'id' => $thread->uuid,
            'title' => $thread->title ?: 'Thread',
            'purpose' => $thread->purpose,
            'phase' => $thread->phase,
            'status' => $thread->status,
            'threadable_type' => $this->resourceType($thread->threadable_type),
            'threadable_id' => $thread->threadable instanceof Space ? $thread->threadable->uuid : $thread->threadable_id,
            'message_count' => $thread->messages()->count(),
            'post_count' => $thread->posts()->count(),
            'created_at' => optional($thread->created_at)?->toIso8601String(),
            'updated_at' => optional($thread->updated_at)?->toIso8601String(),
        ];
    }

    public function mapGraphNode(Model $node): array
    {
        return match (true) {
            $node instanceof Space => [
                'type' => 'space',
                'id' => $node->uuid,
                'resource' => $this->mapSpace($node),
            ],
            $node instanceof Thread => [
                'type' => 'thread',
                'id' => $node->uuid,
                'resource' => $this->mapThread($node),
            ],
            $node instanceof Post => [
                'type' => 'post',
                'id' => $node->ulid,
                'resource' => $this->mapPost($node),
            ],
            default => abort(422, 'Unsupported graph node model.'),
        };
    }

    /**
     * @param  array<string, mixed>  $edge
     * @return array<string, mixed>
     */
    public function mapGraphEdge(array $edge): array
    {
        /** @var SpaceRelation|ThreadRelation|PostRelation $relation */
        $relation = $edge['relation'];
        /** @var Model $source */
        $source = $edge['source'];
        /** @var Model $target */
        $target = $edge['target'];

        return [
            'direction' => (string) $edge['direction'],
            'depth' => (int) $edge['depth'],
            'type' => $relation instanceof PostRelation ? $relation->role : $relation->type,
            'purpose' => $relation instanceof PostRelation ? null : $relation->purpose,
            'source' => $this->mapGraphNode($source),
            'target' => $this->mapGraphNode($target),
            'created_at' => optional($relation->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedGraphNodeTypes(): array
    {
        return ['space', 'thread', 'post'];
    }

    public function mapPost(Post $post): array
    {
        return [
            'id' => $post->ulid,
            'type' => $post->type,
            'status' => $post->status,
            'payload' => is_array($post->payload) ? $post->payload : [],
            'meta' => is_array($post->meta) ? $post->meta : [],
            'occurred_at' => optional($post->occurred_at)?->toIso8601String(),
            'postable_type' => $this->resourceType($post->postable_type),
            'postable_id' => $post->postable instanceof Space ? $post->postable->uuid : ($post->postable instanceof Thread ? $post->postable->uuid : $post->postable_id),
            'created_at' => optional($post->created_at)?->toIso8601String(),
        ];
    }

    public function mapActor(ThreadActor $threadActor): array
    {
        return [
            'id' => $threadActor->id,
            'actor_reference' => $threadActor->actorReference(),
            'actor_type' => $this->resourceType($threadActor->actorable_type),
            'actor_id' => $threadActor->actorable_id,
            'role' => $threadActor->role,
            'status' => $threadActor->status,
            'priority' => $threadActor->priority,
            'config' => is_array($threadActor->config) ? $threadActor->config : [],
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedNamedActors(): array
    {
        return [
            AgentActors::Coordinator,
            AgentActors::SafetyGuard,
            AgentActors::AssistantSuggester,
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedActorRoles(): array
    {
        return [
            AgentActors::RolePresenter,
            AgentActors::RoleObserver,
            ThreadActor::RoleListener,
            ThreadActor::RoleMember,
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedActorStatuses(): array
    {
        return [
            ThreadActor::StatusActive,
            ThreadActor::StatusPaused,
        ];
    }

    public function mapMessage(Post $message): array
    {
        return [
            'id' => $message->ulid,
            'type' => $message->type,
            'text' => $message->text,
            'sender_type' => $this->resourceType($message->senderable_type),
            'sender_id' => $message->senderable_id,
            'attachments' => is_array($message->attachments) ? $message->attachments : [],
            'meta' => is_array($message->meta) ? $message->meta : [],
            'created_at' => optional($message->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchContext(User $actor, string $query, ?Space $space = null, ?Thread $thread = null, int $limit = 10): array
    {
        $needle = mb_strtolower(trim($query));
        abort_if($needle === '', 422, 'A non-empty query is required.');

        $results = [];
        $remaining = $this->clampLimit($limit);
        $searchThreads = $thread ? collect([$thread]) : ($space ? $space->conversationThreads() : $this->visibleThreads($actor, $remaining));

        foreach ($searchThreads as $contextThread) {
            foreach ($contextThread->messages()->latest('id')->get() as $message) {
                if ($remaining === 0) {
                    return $results;
                }

                if (! $this->contains($message->text, $needle)) {
                    continue;
                }

                $results[] = [
                    'kind' => 'message',
                    'thread_id' => $contextThread->uuid,
                    'result' => $this->mapMessage($message),
                    'excerpt' => $this->excerpt($message->text, $needle),
                ];
                $remaining--;
            }
        }

        if ($remaining === 0) {
            return $results;
        }

        $posts = $thread
            ? $thread->posts()->latest('id')->get()
            : ($space ? $space->conversationPosts()->sortByDesc('id')->values() : $this->visiblePosts($actor, $remaining));

        foreach ($posts as $post) {
            if ($remaining === 0) {
                return $results;
            }

            $haystacks = [
                $post->type,
                $post->status,
                json_encode($post->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($post->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];

            if (! $this->contains($haystacks, $needle)) {
                continue;
            }

            $results[] = [
                'kind' => 'post',
                'post_id' => $post->ulid,
                'result' => $this->mapPost($post),
                'excerpt' => $this->excerpt(implode(' ', array_filter($haystacks)), $needle),
            ];
            $remaining--;
        }

        if ($remaining === 0) {
            return $results;
        }

        foreach ($this->storesForScope($space, $thread) as $store) {
            foreach ($store->documents()->with('media')->latest('id')->get() as $document) {
                if ($remaining === 0) {
                    return $results;
                }

                $media = $document->media;
                $haystacks = [
                    $store->name,
                    $document->origin,
                    $document->provider_file_id,
                    $document->provider_document_id,
                    json_encode($document->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $media instanceof Media ? $media->name : null,
                    $media instanceof Media ? $media->file_name : null,
                ];

                if (! $this->contains($haystacks, $needle)) {
                    continue;
                }

                $results[] = [
                    'kind' => 'store_document',
                    'store_id' => $store->uuid,
                    'result' => $this->mapStoreDocument($document, $store),
                    'excerpt' => $this->excerpt(implode(' ', array_filter($haystacks)), $needle),
                ];
                $remaining--;
            }
        }

        return $results;
    }

    protected function clampLimit(int $limit, int $min = 1, int $max = 25): int
    {
        return max($min, min($max, $limit));
    }

    /**
     * @return Collection<int, Thread>
     */
    protected function visibleThreads(User $actor, int $limit): Collection
    {
        return Thread::query()
            ->latest('id')
            ->get()
            ->filter(fn (Thread $thread): bool => Gate::forUser($actor)->allows('view', $thread))
            ->take($this->clampLimit($limit))
            ->values();
    }

    /**
     * @return Collection<int, Post>
     */
    protected function visiblePosts(User $actor, int $limit): Collection
    {
        return Post::query()
            ->latest('id')
            ->get()
            ->filter(function (Post $post) use ($actor): bool {
                $postable = $post->postable;

                return match (true) {
                    $postable instanceof Space => Gate::forUser($actor)->allows('view', $postable),
                    $postable instanceof Thread => Gate::forUser($actor)->allows('view', $postable),
                    default => false,
                };
            })
            ->take($this->clampLimit($limit))
            ->values();
    }

    /**
     * @return Collection<int, Store>
     */
    protected function storesForScope(?Space $space, ?Thread $thread): Collection
    {
        if ($thread instanceof Thread) {
            $stores = $thread->stores()->get();

            if ($thread->threadable instanceof Space) {
                return $stores->merge($thread->threadable->stores()->get())->unique('id')->values();
            }

            return $stores;
        }

        if ($space instanceof Space) {
            return $space->stores()->get();
        }

        return collect();
    }

    protected function mapStoreDocument(StoreDocument $document, Store $store): array
    {
        $media = $document->media;

        return [
            'id' => $document->id,
            'store_id' => $store->uuid,
            'origin' => $document->origin,
            'status' => $document->status,
            'provider_file_id' => $document->provider_file_id,
            'provider_document_id' => $document->provider_document_id,
            'meta' => is_array($document->meta) ? $document->meta : [],
            'media' => [
                'id' => $media?->id,
                'name' => $media?->name,
                'file_name' => $media?->file_name,
                'mime_type' => $media?->mime_type,
            ],
        ];
    }

    protected function contains(array|string|null $haystacks, string $needle): bool
    {
        foreach ((array) $haystacks as $haystack) {
            if (! is_string($haystack) || trim($haystack) === '') {
                continue;
            }

            if (mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function excerpt(?string $text, string $needle): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $position = mb_stripos($text, $needle);

        if ($position === false) {
            return mb_substr($text, 0, 180);
        }

        $start = max(0, $position - 60);

        return trim(mb_substr($text, $start, 180));
    }

    protected function resourceType(?string $type): ?string
    {
        if (! is_string($type) || $type === '') {
            return null;
        }

        return class_basename($type);
    }
}
