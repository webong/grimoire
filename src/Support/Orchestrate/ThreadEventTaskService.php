<?php

namespace Webong\LaravelGouge\Support\Orchestrate;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Webong\LaravelGouge\Support\AgentEvents;

class ThreadEventTaskService
{
    public function __construct(
        protected MessageTaskService $messageTaskService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createLocalTask(
        Post $promptMessage,
        ?User $user = null,
        array $payload = [],
        ?string $stateOverride = null,
    ): TaskRecord {
        $existing = $this->findLocalTaskForPromptMessage($promptMessage);
        $snapshot = $this->messageTaskService->snapshot($promptMessage);
        $thread = $this->resolvePromptThread($promptMessage);
        $state = $stateOverride ?? $snapshot['state'];
        $protocol = $this->trimmedString(data_get($payload, 'local.protocol')) ?? $existing?->protocol;
        $owner = is_array(data_get($payload, 'local.owner')) ? data_get($payload, 'local.owner') : $existing?->owner;
        $publicId = $this->trimmedString(data_get($payload, 'local.public_id')) ?? $existing?->publicId;
        $task = $this->buildLocalTaskPayload(
            taskUuid: $existing?->uuid ?? (string) Str::uuid7(),
            publicId: $publicId,
            state: $state,
            promptMessage: $promptMessage,
            thread: $snapshot['thread'] ?? $thread,
            spaceUuid: $snapshot['space']?->uuid,
            user: $user,
            protocol: $protocol,
            owner: $owner,
            payload: $this->mergedPayload($existing?->lastPayload ?? [], $payload),
            snapshot: [
                'state' => $state,
                'invocations' => $this->messageTaskService->invocationPayload($snapshot['invocations']),
                'artifacts' => $snapshot['assistant_replies']
                    ->map(fn (Post $message): array => $this->messageTaskService->basicArtifactPayload($message))
                    ->values()
                    ->all(),
                'updated_at' => now()->toIso8601String(),
            ],
            previous: $existing,
        );

        if ($existing instanceof TaskRecord && $this->sameTaskPayload($existing, $task)) {
            return $existing;
        }

        return $this->recordSnapshot(
            thread: $thread,
            message: $promptMessage,
            user: $user,
            task: $task,
            kind: $this->kindForProtocol($protocol),
            threadActor: null,
        );
    }

    public function syncLocalTask(TaskRecord $task, ?string $stateOverride = null): TaskRecord
    {
        if ($task->isRemote()) {
            return $task;
        }

        $promptMessage = $task->message;

        if (! $promptMessage instanceof Post) {
            return $task;
        }

        return $this->createLocalTask(
            promptMessage: $promptMessage,
            user: $this->resolveUser($task),
            payload: $task->lastPayload,
            stateOverride: $stateOverride,
        );
    }

    public function syncLocalTaskForPromptMessage(Post $promptMessage, ?string $stateOverride = null): ?TaskRecord
    {
        $task = $this->findLocalTaskForPromptMessage($promptMessage);

        if (! $task instanceof TaskRecord) {
            return null;
        }

        return $this->syncLocalTask($task, $stateOverride);
    }

    public function resolveOwnedAcpTask(User $actor, string $taskId): ?TaskRecord
    {
        return $this->taskRecordsQuery()
            ->where('kind', AgentEvents::Acp)
            ->latest('id')
            ->get()
            ->map(fn (ThreadEvent $event): ?TaskRecord => TaskRecord::fromEvent($event))
            ->filter(fn (mixed $task): bool => $task instanceof TaskRecord)
            ->first(function (TaskRecord $task) use ($actor, $taskId): bool {
                if (! $task->isLocal()) {
                    return false;
                }

                if ($task->uuid !== $taskId && $task->publicId !== $taskId) {
                    return false;
                }

                return $task->userId === $actor->getKey();
            });
    }

    public function resolveOwnedPostInvocationTask(User $actor, string $taskId): ?TaskRecord
    {
        return $this->taskRecordsQuery()
            ->where('kind', AgentEvents::Orchestration)
            ->latest('id')
            ->get()
            ->map(fn (ThreadEvent $event): ?TaskRecord => TaskRecord::fromEvent($event))
            ->filter(fn (mixed $task): bool => $task instanceof TaskRecord)
            ->first(function (TaskRecord $task) use ($actor, $taskId): bool {
                if (! $task->isLocal()) {
                    return false;
                }

                if ($task->protocol !== 'post_invocation') {
                    return false;
                }

                if ($task->uuid !== $taskId && $task->publicId !== $taskId) {
                    return false;
                }

                return $task->userId === $actor->getKey();
            });
    }

    /**
     * @param  array{subject_type: string, subject_id: int|string, token_id?: int|null}|null  $owner
     */
    public function resolveOwnedA2aTask(string $taskId, ?array $owner): ?TaskRecord
    {
        if (! is_array($owner)) {
            return null;
        }

        return $this->taskRecordsQuery()
            ->where('kind', AgentEvents::A2a)
            ->latest('id')
            ->get()
            ->map(fn (ThreadEvent $event): ?TaskRecord => TaskRecord::fromEvent($event))
            ->filter(fn (mixed $task): bool => $task instanceof TaskRecord)
            ->first(function (TaskRecord $task) use ($owner, $taskId): bool {
                if (! $task->isLocal()) {
                    return false;
                }

                if ($task->uuid !== $taskId && $task->publicId !== $taskId) {
                    return false;
                }

                return $this->matchesOwner($task, $owner);
            });
    }

    /**
     * @param  array{subject_type: string, subject_id: int|string, token_id?: int|null}|null  $owner
     * @return Collection<int, TaskRecord>
     */
    public function listOwnedA2aTasks(?array $owner, ?string $userUuid, int $limit): Collection
    {
        if (! is_array($owner)) {
            return collect();
        }

        return $this->taskRecordsQuery()
            ->where('kind', AgentEvents::A2a)
            ->latest('id')
            ->get()
            ->map(fn (ThreadEvent $event): ?TaskRecord => TaskRecord::fromEvent($event))
            ->filter(fn (mixed $task): bool => $task instanceof TaskRecord)
            ->filter(function (TaskRecord $task) use ($owner, $userUuid): bool {
                if (! $task->isLocal() || ! $this->matchesOwner($task, $owner)) {
                    return false;
                }

                if (! is_string($userUuid) || trim($userUuid) === '') {
                    return true;
                }

                return $task->userUuid === trim($userUuid);
            })
            ->unique(fn (TaskRecord $task): string => $task->uuid)
            ->take($limit)
            ->values();
    }

    /**
     * @param  Collection<int, ThreadActor>  $presenters
     */
    public function cancelLocalTask(TaskRecord $task, Collection $presenters, ?string $canceledMetaPath = null): TaskRecord
    {
        $promptMessage = $task->message;

        if (! $promptMessage instanceof Post) {
            return $task;
        }

        $this->messageTaskService->cancelPrompt(
            promptMessage: $promptMessage,
            presenters: $presenters,
            canceledMetaPath: $canceledMetaPath,
        );

        return $this->syncLocalTask($task);
    }

    public function publicId(TaskRecord $task): string
    {
        return $task->publicId;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function recordSnapshot(
        Thread $thread,
        ?Post $message,
        ?User $user,
        array $task,
        string $kind,
        ?ThreadActor $threadActor = null,
        ?string $operation = null,
        ?string $eventType = null,
    ): TaskRecord {
        $resolvedState = (string) ($task['status'] ?? 'submitted');
        $resolvedOperation = $operation ?? $this->operationForState($resolvedState);
        $resolvedEventType = $eventType ?? $resolvedOperation;

        $event = $thread->events()->create([
            'thread_actor_id' => $threadActor?->id,
            'post_id' => $message?->id,
            'event_key' => 'agent_task',
            'layer' => ThreadEvent::LayerExecution,
            'kind' => $kind,
            'operation' => $resolvedOperation,
            'state' => $resolvedState,
            'event_type' => $resolvedEventType,
            'severity' => $this->severityForState($resolvedState),
            'payload' => [
                'task' => $task,
            ],
        ]);

        $event->setRelation('thread', $thread);

        if ($message instanceof Post) {
            $event->setRelation('post', $message);
        }

        return TaskRecord::fromEvent($event) ?? throw new RuntimeException('Task snapshot payload is invalid.');
    }

    /**
     * @return Collection<int, TaskRecord>
     */
    public function latestTaskRecords(): Collection
    {
        return $this->taskRecordsQuery()
            ->latest('id')
            ->get()
            ->map(fn (ThreadEvent $event): ?TaskRecord => TaskRecord::fromEvent($event))
            ->filter(fn (mixed $task): bool => $task instanceof TaskRecord)
            ->unique(fn (TaskRecord $task): string => $task->uuid)
            ->values();
    }

    protected function findLocalTaskForPromptMessage(Post $promptMessage): ?TaskRecord
    {
        return $this->taskRecordsQuery()
            ->where('post_id', $promptMessage->getKey())
            ->latest('id')
            ->get()
            ->map(fn (ThreadEvent $event): ?TaskRecord => TaskRecord::fromEvent($event))
            ->filter(fn (mixed $task): bool => $task instanceof TaskRecord)
            ->first(fn (TaskRecord $task): bool => $task->isLocal());
    }

    /**
     * @param  array<string, mixed>|null  $owner
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    protected function buildLocalTaskPayload(
        string $taskUuid,
        ?string $publicId,
        string $state,
        Post $promptMessage,
        ?Thread $thread,
        ?string $spaceUuid,
        ?User $user,
        ?string $protocol,
        ?array $owner,
        array $payload,
        array $snapshot,
        ?TaskRecord $previous,
    ): array {
        $timestamps = [
            'completed_at' => $state === 'completed' ? ($previous?->completedAt ?? now()->toIso8601String()) : $previous?->completedAt,
            'failed_at' => $state === 'failed' ? ($previous?->failedAt ?? now()->toIso8601String()) : $previous?->failedAt,
            'canceled_at' => $state === 'canceled' ? ($previous?->canceledAt ?? now()->toIso8601String()) : $previous?->canceledAt,
        ];

        return [
            'uuid' => $taskUuid,
            'public_id' => $publicId ?? $taskUuid,
            'status' => $state,
            'protocol' => $protocol,
            'owner' => $owner,
            'remote' => null,
            'user_id' => $user?->getKey() ?? $previous?->userId,
            'user_uuid' => $user?->uuid ?? $previous?->userUuid,
            'prompt_post_id' => $promptMessage->getKey(),
            'prompt_post_ulid' => $promptMessage->ulid,
            'thread_id' => $thread?->uuid,
            'space_id' => $spaceUuid,
            'snapshot' => $snapshot,
            'last_payload' => $payload,
            'timestamps' => array_filter($timestamps, fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    protected function mergedPayload(array $existing, array $incoming): array
    {
        $existingLocal = is_array($existing['local'] ?? null) ? $existing['local'] : [];
        $incomingLocal = is_array($incoming['local'] ?? null) ? $incoming['local'] : [];

        return [
            ...$existing,
            ...$incoming,
            'local' => [
                ...$existingLocal,
                ...$incomingLocal,
            ],
        ];
    }

    protected function resolvePromptThread(Post $promptMessage): Thread
    {
        $thread = $this->messageTaskService->resolveMessageThread($promptMessage);

        if (! $thread instanceof Thread) {
            throw new RuntimeException('Prompt message must belong to a thread.');
        }

        return $thread;
    }

    protected function resolveUser(TaskRecord $task): ?User
    {
        if (! is_int($task->userId)) {
            return null;
        }

        return User::query()->find($task->userId);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    protected function sameTaskPayload(TaskRecord $existing, array $task): bool
    {
        return $this->taskPayloadFromRecord($existing) === $task;
    }

    /**
     * @return array<string, mixed>
     */
    protected function taskPayloadFromRecord(TaskRecord $task): array
    {
        return [
            'uuid' => $task->uuid,
            'public_id' => $task->publicId,
            'status' => $task->status,
            'protocol' => $task->protocol,
            'owner' => $task->owner,
            'remote' => $task->remote,
            'user_id' => $task->userId,
            'user_uuid' => $task->userUuid,
            'prompt_post_id' => $task->message?->id,
            'prompt_post_ulid' => $task->message?->ulid,
            'thread_id' => $task->thread?->uuid,
            'space_id' => $task->spaceId,
            'snapshot' => $task->snapshot,
            'last_payload' => $task->lastPayload,
            'timestamps' => array_filter([
                'completed_at' => $task->completedAt,
                'failed_at' => $task->failedAt,
                'canceled_at' => $task->canceledAt,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    protected function taskRecordsQuery(): Builder
    {
        return ThreadEvent::query()
            ->where('event_key', 'agent_task')
            ->where('layer', ThreadEvent::LayerExecution)
            ->with(['thread', 'post']);
    }

    /**
     * @param  array{subject_type: string, subject_id: int|string, token_id?: int|null}  $owner
     */
    protected function matchesOwner(TaskRecord $task, array $owner): bool
    {
        if (! is_array($task->owner)) {
            return false;
        }

        return ($task->owner['subject_type'] ?? null) === $owner['subject_type']
            && (string) ($task->owner['subject_id'] ?? '') === (string) $owner['subject_id'];
    }

    protected function kindForProtocol(?string $protocol): string
    {
        return match ($protocol) {
            'acp' => AgentEvents::Acp,
            'a2a' => AgentEvents::A2a,
            default => AgentEvents::Orchestration,
        };
    }

    protected function severityForState(string $state): string
    {
        return in_array($state, ['failed', 'canceled'], true) ? 'medium' : 'low';
    }

    protected function operationForState(string $state): string
    {
        return match ($state) {
            'completed' => 'task.completed',
            'failed' => 'task.failed',
            'canceled' => 'task.canceled',
            default => 'task.snapshot',
        };
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
