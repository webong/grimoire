<?php

namespace Webong\Gouge\Http\Controllers\Api\A2a;

use App\Http\Controllers\Controller;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webong\Gouge\Ai\Support\A2a\A2aMethodRouter;
use Webong\Gouge\Http\Requests\Server\A2a\HandleA2aRpcRequest;

class StreamController extends Controller
{
    public function __invoke(HandleA2aRpcRequest $request, A2aMethodRouter $router): StreamedResponse
    {
        $payload = $request->validated();
        $id = $payload['id'] ?? null;
        $method = $this->normalizeMethod((string) ($payload['method'] ?? ''));
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        return response()->eventStream(function () use ($router, $method, $params, $id) {
            if (! in_array($method, ['message/stream', 'tasks/resubscribe'], true)) {
                yield $this->event('a2a.error', [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32601,
                        'message' => 'Only message/stream and tasks/resubscribe are supported on this endpoint.',
                    ],
                ]);

                return;
            }

            $resolved = $router->handle($method, $params);

            if (is_array($resolved['error'] ?? null)) {
                yield $this->event('a2a.error', [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => $resolved['error'],
                ]);

                return;
            }

            $initialResult = is_array($resolved['result'] ?? null) ? $resolved['result'] : [];
            $task = is_array($initialResult['task'] ?? null) ? $initialResult['task'] : [];

            yield $this->event('a2a.task', [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $initialResult,
            ]);

            $taskId = $this->resolveTaskId($task, $params);

            if ($taskId === null) {
                yield $this->event('a2a.done', [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => $initialResult,
                ]);

                return;
            }

            $pollIntervalMs = $this->resolvePollIntervalMs($params);
            $maxDurationSeconds = $this->resolveMaxDurationSeconds($params);
            $startedAt = microtime(true);
            $lastKeepAliveAt = microtime(true);
            $lastDigest = $this->taskDigest($task);

            if ($this->isTerminalTaskState($task)) {
                yield $this->event('a2a.done', [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'task' => $task,
                    ],
                ]);

                return;
            }

            while (! connection_aborted()) {
                $elapsed = microtime(true) - $startedAt;
                if ($elapsed >= $maxDurationSeconds) {
                    yield $this->event('a2a.timeout', [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'task' => $task,
                            'elapsed_seconds' => round($elapsed, 3),
                        ],
                    ]);

                    return;
                }

                usleep($pollIntervalMs * 1000);

                $snapshot = $router->handle('tasks/get', ['task_id' => $taskId]);

                if (is_array($snapshot['error'] ?? null)) {
                    yield $this->event('a2a.error', [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'error' => $snapshot['error'],
                    ]);

                    return;
                }

                $snapshotResult = is_array($snapshot['result'] ?? null) ? $snapshot['result'] : [];
                $snapshotTask = is_array($snapshotResult['task'] ?? null) ? $snapshotResult['task'] : [];

                if ($snapshotTask === []) {
                    continue;
                }

                $task = $snapshotTask;
                $snapshotDigest = $this->taskDigest($snapshotTask);

                if ($snapshotDigest !== $lastDigest) {
                    $lastDigest = $snapshotDigest;
                    yield $this->event('a2a.task', [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'task' => $snapshotTask,
                        ],
                    ]);
                }

                if ($this->isTerminalTaskState($snapshotTask)) {
                    yield $this->event('a2a.done', [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'task' => $snapshotTask,
                        ],
                    ]);

                    return;
                }

                if ((microtime(true) - $lastKeepAliveAt) >= 15) {
                    $lastKeepAliveAt = microtime(true);
                    yield $this->event('a2a.ping', [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'task_id' => $taskId,
                            'timestamp' => now()->toIso8601String(),
                        ],
                    ]);
                }
            }
        }, [
            'Connection' => 'keep-alive',
        ], endStreamWith: null);
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $params
     */
    protected function resolveTaskId(array $task, array $params): ?string
    {
        $taskId = $task['id'] ?? $params['taskId'] ?? $params['task_id'] ?? $params['id'] ?? null;

        if (! is_string($taskId) || trim($taskId) === '') {
            return null;
        }

        return trim($taskId);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function resolvePollIntervalMs(array $params): int
    {
        $raw = $params['poll_interval_ms'] ?? $params['pollIntervalMs'] ?? null;
        $interval = is_numeric($raw) ? (int) $raw : 1000;

        return max(250, min(5000, $interval));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function resolveMaxDurationSeconds(array $params): int
    {
        $raw = $params['max_duration_seconds'] ?? $params['maxDurationSeconds'] ?? null;
        $duration = is_numeric($raw) ? (int) $raw : 45;

        return max(5, min(300, $duration));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    protected function taskDigest(array $task): string
    {
        $state = data_get($task, 'status.state');
        $artifacts = is_array($task['artifacts'] ?? null) ? $task['artifacts'] : [];
        $artifactIds = collect($artifacts)
            ->map(fn (mixed $artifact): ?string => is_array($artifact) && is_string($artifact['id'] ?? null) ? trim((string) $artifact['id']) : null)
            ->filter()
            ->values()
            ->all();

        return sha1(json_encode([
            'state' => $state,
            'artifact_ids' => $artifactIds,
            'artifact_count' => count($artifactIds),
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    protected function isTerminalTaskState(array $task): bool
    {
        $state = data_get($task, 'status.state');

        if (! is_string($state) || trim($state) === '') {
            return false;
        }

        return in_array(Str::lower(trim($state)), ['completed', 'failed', 'canceled'], true);
    }

    protected function normalizeMethod(string $method): string
    {
        $canonicalToSlash = [
            'SendStreamingMessage' => 'message/stream',
            'TaskResubscription' => 'tasks/resubscribe',
        ];

        return $canonicalToSlash[$method] ?? $method;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function event(string $event, array $payload): StreamedEvent
    {
        return new StreamedEvent($event, $payload);
    }
}
