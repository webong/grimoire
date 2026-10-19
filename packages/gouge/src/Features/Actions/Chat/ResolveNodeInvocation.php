<?php

namespace Webong\Gouge\Features\Actions\Chat;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Support\Collection;
use Webong\Gouge\Models\Server\AgentConversationMessage;

class ResolveNodeInvocation
{
    /**
     * @return array<string, mixed>|null
     */
    public function execute(User $actor, Space|Thread|Post $node): ?array
    {
        $postIds = $this->postIds($node);

        $invocation = AgentConversationMessage::query()
            ->where('participant_type', $actor->getMorphClass())
            ->where('participant_id', $actor->getKey())
            ->whereNotNull('invocation_id')
            ->where(function ($query) use ($node, $postIds): void {
                $query->where(function ($nodeQuery) use ($node): void {
                    $nodeQuery
                        ->where('invocable_type', $node->getMorphClass())
                        ->where('invocable_id', $node->getKey());
                });

                if ($postIds->isNotEmpty()) {
                    $query->orWhere(function ($postQuery) use ($postIds): void {
                        $postQuery
                            ->where('invocable_type', (new Post)->getMorphClass())
                            ->whereIn('invocable_id', $postIds->all());
                    });
                }
            })
            ->latest('created_at')
            ->first();

        if (! $invocation instanceof AgentConversationMessage) {
            return null;
        }

        $meta = $this->decodeJsonArray($invocation->meta);
        $status = is_string($meta['status'] ?? null)
            ? trim($meta['status'])
            : null;

        if (! in_array($status, ['pending', 'completed', 'failed'], true)) {
            $status = trim((string) $invocation->content) !== ''
                ? 'completed'
                : 'pending';
        }

        return [
            'id' => $invocation->invocation_id,
            'invocation_id' => $invocation->invocation_id,
            'trace_id' => $invocation->trace_id,
            'parent_invocation_id' => $invocation->parent_invocation_id,
            'status' => $status,
            'created_at' => optional($invocation->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, int>
     */
    protected function postIds(Space|Thread|Post $node): Collection
    {
        return match (true) {
            $node instanceof Space => $node->conversationPosts()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values(),
            $node instanceof Thread => $this->threadPostIds($node),
            $node instanceof Post => collect([(int) $node->getKey()]),
        };
    }

    /**
     * @param  array<int, bool>  $visitedThreadIds
     * @return Collection<int, int>
     */
    protected function threadPostIds(Thread $thread, array &$visitedThreadIds = []): Collection
    {
        $threadId = (int) $thread->getKey();

        if ($threadId <= 0 || isset($visitedThreadIds[$threadId])) {
            return collect();
        }

        $visitedThreadIds[$threadId] = true;
        $postIds = $thread->posts()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        foreach ($thread->threads()->get() as $childThread) {
            $postIds = $postIds->concat($this->threadPostIds($childThread, $visitedThreadIds));
        }

        return $postIds->unique()->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
