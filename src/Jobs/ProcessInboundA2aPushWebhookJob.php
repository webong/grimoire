<?php

namespace Webong\LaravelGouge\Jobs;

use App\Features\Actions\Chat\DispatchThreadMessage;
use App\Features\Actions\Chat\ThreadMessageEntry;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadEvent;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use Webong\LaravelGouge\Support\AgentEvents;
use Webong\LaravelGouge\Support\Orchestrate\TaskRecord;
use Webong\LaravelGouge\Support\Orchestrate\ThreadEventTaskService;

class ProcessInboundA2aPushWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload ?? [];

        if (! is_array($payload)) {
            return;
        }

        $taskId = $this->resolveTaskId($payload);
        $remoteAgentId = $this->resolveRemoteAgentId();
        $state = $this->resolveState($payload);
        $link = $this->resolveLink($taskId, $remoteAgentId);

        if ($link) {
            $link = $this->updateLink($link, $state, $payload);
        }

        $thread = $link?->thread ?? $this->resolveThreadFromPayload($payload);

        if (! $thread) {
            return;
        }

        $message = $link ? $this->writeRemoteResponseMessage($link, $payload, $state, $taskId, $remoteAgentId) : null;

        $threadEvent = $thread->events()->create([
            'thread_actor_id' => null,
            'post_id' => $message?->id,
            'event_key' => 'a2a_push_webhook',
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::A2a,
            'operation' => 'push.notification',
            'state' => ThreadEvent::StateReceived,
            'event_type' => 'a2a.push.received',
            'severity' => 'low',
            'payload' => [
                'task_id' => $taskId,
                'status' => $state,
                'remote_agent_id' => $remoteAgentId,
                'mapped' => $link !== null,
                'event' => $payload['method'] ?? ($payload['event'] ?? null),
                'webhook_call_id' => $this->webhookCall->id,
            ],
        ]);

        if ($link) {
            $link->threadEvents()->syncWithoutDetaching([$threadEvent->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveTaskId(array $payload): ?string
    {
        $taskId = data_get($payload, 'params.id') ?? data_get($payload, 'task.id');

        if (! is_string($taskId) || trim($taskId) === '') {
            return null;
        }

        return trim($taskId);
    }

    protected function resolveRemoteAgentId(): ?string
    {
        $headers = is_array($this->webhookCall->headers ?? null) ? $this->webhookCall->headers : [];
        $value = $this->headerValue($headers, 'x-a2a-remote-agent');

        if ($value !== null) {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveState(array $payload): ?string
    {
        $state = data_get($payload, 'params.statusUpdate.status.state') ?? data_get($payload, 'task.status.state');

        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        return strtolower(trim($state));
    }

    protected function resolveLink(?string $taskId, ?string $remoteAgentId): ?TaskRecord
    {
        if ($taskId === null) {
            return null;
        }

        return $this->taskService()
            ->latestTaskRecords()
            ->first(function (TaskRecord $task) use ($taskId, $remoteAgentId): bool {
                if ($task->protocol !== 'a2a' || ! $task->isRemote()) {
                    return false;
                }

                if (data_get($task->remote, 'task_id') !== $taskId) {
                    return false;
                }

                if ($remoteAgentId !== null && data_get($task->remote, 'agent_id') !== $remoteAgentId) {
                    return false;
                }

                return true;
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveThreadFromPayload(array $payload): ?Thread
    {
        $threadUuid = data_get($payload, 'params.metadata.threadId')
            ?? data_get($payload, 'params.metadata.thread_id')
            ?? data_get($payload, 'task.context.thread_id');

        if (! is_string($threadUuid) || trim($threadUuid) === '') {
            return null;
        }

        return Thread::query()
            ->where('uuid', trim($threadUuid))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function updateLink(TaskRecord $link, ?string $state, array $payload): TaskRecord
    {
        $resolvedState = $state ?? $link->status;
        $timestamps = [
            'completed_at' => $resolvedState === 'completed' ? ($link->completedAt ?? now()->toIso8601String()) : $link->completedAt,
            'failed_at' => $resolvedState === 'failed' ? ($link->failedAt ?? now()->toIso8601String()) : $link->failedAt,
            'canceled_at' => $resolvedState === 'canceled' ? ($link->canceledAt ?? now()->toIso8601String()) : $link->canceledAt,
        ];
        $thread = $link->thread ?? $this->resolveThreadFromPayload($payload);

        if (! $thread instanceof Thread) {
            return $link;
        }

        return $this->taskService()->recordSnapshot(
            thread: $thread,
            message: $link->message,
            user: null,
            task: [
                'uuid' => $link->uuid,
                'public_id' => $link->publicId,
                'status' => $resolvedState,
                'protocol' => $link->protocol,
                'owner' => $link->owner,
                'remote' => $link->remote,
                'user_id' => $link->userId,
                'user_uuid' => $link->userUuid,
                'thread_id' => $link->thread?->uuid,
                'space_id' => null,
                'snapshot' => [
                    'state' => $resolvedState,
                    'updated_at' => now()->toIso8601String(),
                ],
                'last_payload' => $payload,
                'timestamps' => array_filter($timestamps, fn (mixed $value): bool => $value !== null),
            ],
            kind: AgentEvents::A2a,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function writeRemoteResponseMessage(
        TaskRecord $link,
        array $payload,
        ?string $state,
        ?string $taskId,
        ?string $remoteAgentId
    ): ?Post {
        $thread = $link->thread;

        if (! $thread) {
            return null;
        }

        $body = $this->extractResponseText($payload)
            ?? $this->fallbackBody($state, $taskId, $remoteAgentId);

        $dedupe = Post::query()
            ->forThread($thread)
            ->where('meta->source', 'a2a_remote_response')
            ->where('meta->remote_task_id', $taskId)
            ->where('meta->status', $state)
            ->latest('id')
            ->first();

        if ($dedupe) {
            return $dedupe;
        }

        /** @var DispatchThreadMessage $dispatchThreadMessage */
        $dispatchThreadMessage = app(DispatchThreadMessage::class);

        return $dispatchThreadMessage->execute(ThreadMessageEntry::systemMessage(
            thread: $thread,
            text: $body,
            meta: [
                'remote_agent_id' => $remoteAgentId,
                'remote_task_id' => $taskId,
                'status' => $state,
                'webhook_call_id' => $this->webhookCall->id,
            ],
            source: 'a2a_remote_response',
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractResponseText(array $payload): ?string
    {
        $artifacts = data_get($payload, 'params.finalTask.artifacts');
        if (! is_array($artifacts)) {
            $artifacts = data_get($payload, 'task.artifacts');
        }

        if (! is_array($artifacts)) {
            return null;
        }

        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $text = $artifact['text'] ?? data_get($artifact, 'parts.0.text');
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            return trim($text);
        }

        return null;
    }

    protected function fallbackBody(?string $state, ?string $taskId, ?string $remoteAgentId): string
    {
        $resolvedState = $state ?? 'updated';
        $resolvedTaskId = $taskId ?? 'unknown-task';
        $resolvedAgentId = $remoteAgentId ?? 'remote-agent';

        return "[A2A] {$resolvedAgentId} task {$resolvedTaskId} is {$resolvedState}.";
    }

    protected function taskService(): ThreadEventTaskService
    {
        return app(ThreadEventTaskService::class);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    protected function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (! is_string($key) || strtolower(trim($key)) !== strtolower($name)) {
                continue;
            }

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            return trim($value);
        }

        return null;
    }
}
