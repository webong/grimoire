<?php

namespace Webong\Gouge\Support\Orchestrate;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Illuminate\Support\Collection;
use Webong\Gouge\Support\AgentActors;

class MessageTaskService
{
    /**
     * @return array{
     *     thread: ?Thread,
     *     space: ?Space,
     *     invocations: array<string, mixed>,
     *     assistant_replies: Collection<int, Post>,
     *     state: string
     * }
     */
    public function snapshot(Post $promptMessage): array
    {
        $thread = $this->resolveMessageThread($promptMessage);
        $space = $thread?->threadable instanceof Space ? $thread->threadable : null;
        $invocations = is_array(data_get($promptMessage->meta, 'invocations')) ? data_get($promptMessage->meta, 'invocations') : [];
        $assistantReplies = $this->assistantRepliesForPrompt($thread, $promptMessage);

        return [
            'thread' => $thread,
            'space' => $space,
            'invocations' => $invocations,
            'assistant_replies' => $assistantReplies,
            'state' => $this->resolveTaskState($invocations, $assistantReplies),
        ];
    }

    public function resolveMessageThread(Post $message): ?Thread
    {
        if ($message->postable_type !== (new Thread)->getMorphClass()) {
            return null;
        }

        return Thread::query()->find($message->postable_id);
    }

    /**
     * @return Collection<int, Post>
     */
    public function assistantRepliesForPrompt(?Thread $thread, Post $promptMessage): Collection
    {
        if (! $thread instanceof Thread) {
            return collect();
        }

        return Post::query()
            ->forThread($thread)
            ->withoutSender()
            ->where('meta->source', 'agent_response')
            ->where('meta->in_reply_to_post_id', $promptMessage->id)
            ->oldest('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $invocations
     * @return list<array<string, mixed>>
     */
    public function invocationPayload(array $invocations): array
    {
        return collect($invocations)
            ->map(function (mixed $entry, string $actorKey): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                return [
                    'actor_key' => $actorKey,
                    'status' => $entry['status'] ?? null,
                    'invocation_id' => $entry['invocation_id'] ?? null,
                    'conversation_id' => $entry['conversation_id'] ?? null,
                    'error_message' => $entry['error_message'] ?? null,
                    'recorded_at' => $entry['recorded_at'] ?? null,
                ];
            })
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function basicArtifactPayload(Post $message): array
    {
        return [
            'id' => $message->ulid,
            'post_id' => $message->id,
            'role' => 'assistant',
            'text' => is_string($message->text) ? $message->text : '',
            'actor_key' => data_get($message->meta, 'actor_key'),
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'a2ui' => is_array(data_get($message->meta, 'a2ui')) ? data_get($message->meta, 'a2ui') : null,
        ];
    }

    /**
     * @param  Collection<int, ThreadActor>  $presenters
     */
    public function cancelPrompt(Post $promptMessage, Collection $presenters, ?string $canceledMetaPath = null): Post
    {
        $meta = is_array($promptMessage->meta) ? $promptMessage->meta : [];
        $invocations = is_array($meta['invocations'] ?? null) ? $meta['invocations'] : [];
        $canceledAt = now()->toIso8601String();

        if ($invocations === []) {
            $presenters->each(function (ThreadActor $presenter) use (&$invocations, $canceledAt): void {
                $actorKey = $this->resolveActorKey($presenter);
                $invocations[$actorKey] = [
                    'status' => 'canceled',
                    'invocation_id' => null,
                    'conversation_id' => null,
                    'conversation_storage_id' => null,
                    'recorded_at' => $canceledAt,
                    'canceled_at' => $canceledAt,
                ];
            });
        } else {
            foreach ($invocations as $actorKey => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $status = $entry['status'] ?? null;
                if (in_array($status, ['completed', 'failed'], true)) {
                    continue;
                }

                $invocations[$actorKey] = [
                    ...$entry,
                    'status' => 'canceled',
                    'recorded_at' => $canceledAt,
                    'canceled_at' => $canceledAt,
                ];
            }
        }

        $meta['invocations'] = $invocations;

        if (is_string($canceledMetaPath) && $canceledMetaPath !== '') {
            data_set($meta, $canceledMetaPath, $canceledAt);
        }

        $promptMessage->forceFill([
            'meta' => $meta,
        ])->save();

        return $promptMessage->fresh();
    }

    /**
     * @param  array<string, mixed>  $invocations
     * @param  Collection<int, Post>|null  $assistantReplies
     */
    public function resolveTaskState(array $invocations, ?Collection $assistantReplies = null): string
    {
        $assistantReplies ??= collect();

        if ($invocations === []) {
            return $assistantReplies->isNotEmpty() ? 'completed' : 'submitted';
        }

        $statuses = collect($invocations)
            ->map(fn (mixed $entry): ?string => is_array($entry) ? $this->trimmedString($entry['status'] ?? null) : null)
            ->filter()
            ->values();

        if ($statuses->isEmpty()) {
            return $assistantReplies->isNotEmpty() ? 'completed' : 'submitted';
        }

        if ($statuses->every(fn (string $status): bool => $status === 'completed')) {
            return 'completed';
        }

        if ($statuses->every(fn (string $status): bool => $status === 'canceled')) {
            return 'canceled';
        }

        if ($statuses->contains(fn (string $status): bool => $status === 'failed') && ! $statuses->contains('pending')) {
            return 'failed';
        }

        if ($statuses->contains(fn (string $status): bool => in_array($status, ['pending', 'canceled'], true))) {
            return 'working';
        }

        return 'working';
    }

    protected function resolveActorKey(ThreadActor $presenter): string
    {
        $actorKey = $presenter->actorName();

        if (! is_string($actorKey) || $actorKey === '') {
            return AgentActors::Coordinator;
        }

        return $actorKey;
    }

    protected function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
