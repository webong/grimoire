<?php

namespace Webong\Gouge\Support\Acp;

use App\Features\Actions\Chat\EnsureThreadMembership;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Webong\Gouge\Features\Actions\Chat\ResolveActiveThreadPresenters;
use Webong\Gouge\Features\Operations\Chat\DispatchPromptOperation;
use Webong\Gouge\Support\AgentActors;
use Webong\Gouge\Support\Orchestrate\MessageTaskService;
use Webong\Gouge\Support\Orchestrate\TaskRecord;
use Webong\Gouge\Support\Orchestrate\ThreadEventTaskService;

class AcpSessionService
{
    public function __construct(
        protected DispatchPromptOperation $dispatchPromptOperation,
        protected EnsureThreadMembership $ensureThreadMembership,
        protected ResolveActiveThreadPresenters $resolveActiveThreadPresenters,
        protected ThreadEventTaskService $taskService,
        protected MessageTaskService $messageTaskService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listSessions(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', Thread::class);

        $spaceIds = $this->visibleSpacesQuery($actor)->pluck('id');

        if ($spaceIds->isEmpty()) {
            return [];
        }

        return Thread::query()
            ->whereHasMorph('threadable', [Space::class], function (Builder $builder) use ($spaceIds): void {
                $builder->whereKey($spaceIds->all());
            })
            ->with('threadable')
            ->withMax('messages', 'created_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Thread $thread): bool => $thread->threadable instanceof Space)
            ->map(fn (Thread $thread): array => $this->sessionPayload($thread, $thread->threadable))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(
        User $actor,
        string $spaceUuid,
        ?string $title = null,
        ?string $purpose = null,
        ?string $phase = null,
    ): array {
        $space = Space::query()
            ->where('uuid', $spaceUuid)
            ->firstOrFail();

        Gate::forUser($actor)->authorize('update', $space);

        $resolvedPurpose = $this->resolvedPurpose($purpose);
        $resolvedPhase = $this->trimmedString($phase) ?? Thread::PhaseInitial;
        $resolvedTitle = $this->trimmedString($title) ?? 'Thread';

        $thread = DB::transaction(function () use ($actor, $space, $resolvedPurpose, $resolvedPhase, $resolvedTitle): Thread {
            $thread = $space->threads()->create([
                'title' => $resolvedTitle,
                'purpose' => $resolvedPurpose,
                'phase' => $resolvedPhase,
                'status' => 'open',
            ]);

            $thread->actors()->create([
                'actorable_type' => AgentActors::Coordinator,
                'actorable_id' => null,
                'role' => AgentActors::RolePresenter,
                'status' => ThreadActor::StatusActive,
                'priority' => 1,
                'config' => null,
            ]);

            $this->ensureThreadMembership->execute($thread, $actor);
            $this->markActiveThread($space, $actor, $thread);

            return $thread;
        });

        return $this->sessionPayload($thread->fresh(), $space);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadSession(User $actor, string $sessionUuid): array
    {
        $thread = $this->resolveThread($actor, $sessionUuid);
        $space = $this->resolveThreadSpace($thread);
        $messages = $thread->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (Post $message): array => $this->messagePayload($message))
            ->values()
            ->all();

        return [
            ...$this->sessionPayload($thread, $space),
            'messages' => $messages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function promptSession(User $actor, string $sessionUuid, ?string $spaceUuid, string $text): array
    {
        $thread = $this->resolveThread($actor, $sessionUuid);
        $space = $this->resolveThreadSpace($thread, $spaceUuid);

        Gate::forUser($actor)->authorize('create', Post::class);

        $text = $this->trimmedString($text);
        abort_if($text === null, 422, 'A text prompt is required.');

        $this->markActiveThread($space, $actor, $thread);

        $dispatch = $this->dispatchPromptOperation->run(
            space: $space,
            thread: $thread,
            actor: $actor,
            text: $text,
            options: [
                'agent_source' => 'acp_prompt',
                'direct_source' => 'acp_prompt',
                'dispatch_observers_when_agent' => false,
                'dispatch_observers_when_direct' => false,
                'ensure_membership' => true,
                'ensure_presenter' => true,
                'presenter_actor_type' => AgentActors::Coordinator,
                'meta' => [
                    'acp' => [
                        'owner' => [
                            'subject_type' => $actor->getMorphClass(),
                            'subject_id' => $actor->getKey(),
                        ],
                        'space_uuid' => $space->uuid,
                        'thread_uuid' => $thread->uuid,
                        'requested_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        );
        $promptMessage = $dispatch['message'];

        $promptMeta = is_array($promptMessage->meta) ? $promptMessage->meta : [];
        $task = $this->taskService->createLocalTask(
            promptMessage: $promptMessage,
            user: $actor,
            payload: [
                'local' => [
                    'protocol' => 'acp',
                    'owner' => [
                        'subject_type' => $actor->getMorphClass(),
                        'subject_id' => $actor->getKey(),
                    ],
                ],
            ],
        );
        $promptMeta['acp'] = [
            ...(is_array($promptMeta['acp'] ?? null) ? $promptMeta['acp'] : []),
            'task_id' => $task->uuid,
        ];

        $promptMessage->forceFill([
            'meta' => $promptMeta,
        ])->save();

        $task = $this->taskService->syncLocalTask($task);

        return $this->taskPayload($task);
    }

    /**
     * @return array<string, mixed>
     */
    public function task(User $actor, string $taskId): array
    {
        $task = $this->taskService->resolveOwnedAcpTask($actor, $taskId);
        abort_unless($task instanceof TaskRecord, 404);

        return $this->taskPayload($this->taskService->syncLocalTask($task));
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelTask(User $actor, string $taskId): array
    {
        $task = $this->taskService->resolveOwnedAcpTask($actor, $taskId);
        abort_unless($task instanceof TaskRecord, 404);

        $promptMessage = $task->message;
        abort_unless($promptMessage instanceof Post, 404);
        $thread = $this->messageTaskService->resolveMessageThread($promptMessage);

        if (! $thread instanceof Thread) {
            return $this->taskPayload($task);
        }

        $task = $this->taskService->cancelLocalTask(
            task: $task,
            presenters: $this->resolveActiveThreadPresenters->execute($thread),
            canceledMetaPath: 'acp.canceled_at',
        );

        return $this->taskPayload($task);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cancelSession(User $actor, string $sessionUuid): ?array
    {
        $thread = $this->resolveThread($actor, $sessionUuid);
        $task = $this->taskService->latestTaskRecords()
            ->first(fn (TaskRecord $task): bool => $task->isLocal()
                && $task->protocol === 'acp'
                && $task->thread?->uuid === $thread->uuid
                && $task->userId === $actor->getKey()
                && ! in_array($task->status, ['completed', 'failed', 'canceled'], true));

        if (! $task instanceof TaskRecord) {
            return null;
        }

        return $this->cancelTask($actor, $task->uuid);
    }

    protected function visibleSpacesQuery(User $actor): Builder
    {
        Gate::forUser($actor)->authorize('viewAny', Space::class);

        $query = Space::query()->latest('created_at');

        $query->whereHas('actorStates', function (Builder $builder) use ($actor): void {
            $builder
                ->whereMorphedTo('actor', $actor)
                ->where('status', SpaceActorState::StatusActive);
        });

        return $query;
    }

    protected function resolveThread(User $actor, string $sessionUuid): Thread
    {
        $thread = Thread::query()
            ->where('uuid', $sessionUuid)
            ->firstOrFail();

        Gate::forUser($actor)->authorize('view', $thread);

        return $thread;
    }

    protected function resolveThreadSpace(Thread $thread, ?string $spaceUuid = null): Space
    {
        $space = $thread->threadable;
        abort_unless($space instanceof Space, 404, 'Thread space was not found.');

        if ($spaceUuid !== null && $space->uuid !== $spaceUuid) {
            abort(404, 'Thread space was not found.');
        }

        return $space;
    }

    protected function markActiveThread(Space $space, User $actor, Thread $thread): void
    {
        SpaceActorState::query()->updateOrCreate(
            [
                'space_id' => $space->getKey(),
                'actorable_type' => $actor->getMorphClass(),
                'actorable_id' => $actor->getKey(),
            ],
            [
                'thread_id' => $thread->getKey(),
                'status' => SpaceActorState::StatusActive,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function sessionPayload(Thread $thread, Space $space): array
    {
        return [
            'id' => $thread->uuid,
            'title' => $thread->title ?: 'Thread',
            'purpose' => $thread->purpose,
            'status' => $thread->status,
            'space' => [
                'id' => $space->uuid,
                'status' => $space->status,
            ],
            'created_at' => optional($thread->created_at)?->toIso8601String(),
            'last_message_at' => $this->messageTimestamp($thread),
        ];
    }

    protected function messageTimestamp(Thread $thread): ?string
    {
        $latestMessage = $thread->messages()
            ->latest('created_at')
            ->first();

        return optional($latestMessage?->created_at)?->toIso8601String()
            ?? optional($thread->created_at)?->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    protected function messagePayload(Post $message): array
    {
        return [
            'id' => $message->id,
            'role' => $this->messageRole($message),
            'text' => is_string($message->text) ? $message->text : '',
            'source' => data_get($message->meta, 'source'),
            'created_at' => optional($message->created_at)?->toIso8601String(),
        ];
    }

    protected function messageRole(Post $message): string
    {
        if (data_get($message->meta, 'source') === 'agent_response' || $message->senderable_type === null) {
            return 'assistant';
        }

        return 'user';
    }

    /**
     * @return array<string, mixed>
     */
    protected function taskPayload(TaskRecord $task): array
    {
        $promptMessage = $task->message;
        abort_unless($promptMessage instanceof Post, 404);

        $task = $this->taskService->syncLocalTask($task);
        $snapshot = $this->messageTaskService->snapshot($promptMessage);
        $thread = $snapshot['thread'];
        $space = $snapshot['space'];
        $assistantReplies = $snapshot['assistant_replies'];
        $invocations = $snapshot['invocations'];

        return [
            'id' => $this->taskService->publicId($task),
            'kind' => 'task',
            'state' => $task->status,
            'session_id' => $thread?->uuid,
            'space_id' => $space?->uuid,
            'prompt' => [
                'id' => $promptMessage->id,
                'text' => is_string($promptMessage->text) ? $promptMessage->text : '',
                'created_at' => optional($promptMessage->created_at)?->toIso8601String(),
            ],
            'invocations' => $this->messageTaskService->invocationPayload($invocations),
            'artifacts' => $assistantReplies
                ->map(fn (Post $message): array => $this->messageTaskService->basicArtifactPayload($message))
                ->values()
                ->all(),
        ];
    }

    protected function resolvedPurpose(?string $purpose): string
    {
        return $this->trimmedString($purpose) ?? Thread::PurposeMain;
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
