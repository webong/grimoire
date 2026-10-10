<?php

namespace Webong\Gouge\Ai\Support\A2a;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadEvent;
use Illuminate\Support\Str;
use Spatie\WebhookServer\WebhookCall;
use Throwable;
use Webong\Gouge\Support\AgentEvents;

class TaskPushNotificationDispatcher
{
    public function dispatchTaskUpdate(Post $promptMessage, string $state): void
    {
        $taskId = $this->taskId($promptMessage);

        if ($taskId === null) {
            return;
        }

        $configs = $this->configs($promptMessage);

        if ($configs === []) {
            return;
        }

        foreach ($configs as $config) {
            $url = is_string($config['url'] ?? null) ? trim((string) $config['url']) : '';

            if ($url === '') {
                continue;
            }

            $stateFilter = is_array($config['state_filter'] ?? null) ? $config['state_filter'] : [];

            if ($stateFilter !== [] && ! in_array(strtolower(trim($state)), $stateFilter, true)) {
                continue;
            }

            $payload = [
                'jsonrpc' => '2.0',
                'id' => (string) Str::uuid(),
                'method' => (string) config('a2a.inbound.push_notifications.notification_method', 'SendTaskStreamingNotification'),
                'params' => [
                    'id' => $taskId,
                    'statusUpdate' => [
                        'status' => [
                            'state' => $state,
                            'timestamp' => now()->toIso8601String(),
                        ],
                        'final' => in_array(strtolower(trim($state)), ['completed', 'failed', 'canceled'], true),
                    ],
                    'metadata' => [
                        'promptMessageUlid' => $promptMessage->ulid,
                        'threadId' => $this->threadUuid($promptMessage),
                    ],
                ],
            ];

            try {
                $call = WebhookCall::create()
                    ->url($url)
                    ->payload($payload)
                    ->useTimestamp()
                    ->meta([
                        'a2a_task_id' => $taskId,
                        'a2a_push_config_id' => $config['id'] ?? null,
                    ]);

                $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];

                if ($headers !== []) {
                    $call = $call->withHeaders($headers);
                }

                $secret = is_string($config['secret'] ?? null) ? trim((string) $config['secret']) : '';

                if ($secret !== '') {
                    $call = $call->useSecret($secret);
                } else {
                    $call = $call->doNotSign();
                }

                $call->dispatch();
            } catch (Throwable $exception) {
                $this->recordDispatchFailure($promptMessage, $taskId, $config, $exception->getMessage());
            }
        }
    }

    protected function recordDispatchFailure(Post $promptMessage, string $taskId, array $config, string $errorMessage): void
    {
        $thread = $this->thread($promptMessage);

        if (! $thread) {
            return;
        }

        $thread->events()->create([
            'thread_actor_id' => null,
            'post_id' => $promptMessage->id,
            'event_key' => 'a2a_push_notifications',
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::A2a,
            'operation' => 'push.dispatch',
            'state' => ThreadEvent::StateFailed,
            'event_type' => 'a2a.push.dispatch_failed',
            'severity' => 'medium',
            'payload' => [
                'task_id' => $taskId,
                'push_config_id' => $config['id'] ?? null,
                'url' => $config['url'] ?? null,
                'error_message' => mb_substr(trim($errorMessage), 0, 500),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function configs(Post $promptMessage): array
    {
        $configs = data_get($promptMessage->meta, 'a2a.push_notification_configs');

        if (! is_array($configs)) {
            return [];
        }

        return collect($configs)
            ->filter(fn (mixed $config): bool => is_array($config))
            ->values()
            ->all();
    }

    protected function taskId(Post $promptMessage): ?string
    {
        $taskId = data_get($promptMessage->meta, 'a2a_task_id');

        if (is_string($taskId) && trim($taskId) !== '') {
            return trim($taskId);
        }

        return $promptMessage->ulid ?: null;
    }

    protected function threadUuid(Post $promptMessage): ?string
    {
        $thread = $this->thread($promptMessage);

        return $thread?->uuid;
    }

    protected function thread(Post $promptMessage): ?Thread
    {
        if ($promptMessage->postable_type !== (new Thread)->getMorphClass()) {
            return null;
        }

        return Thread::query()->find($promptMessage->postable_id);
    }
}
