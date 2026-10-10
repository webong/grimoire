<?php

namespace Webong\Gouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use App\Support\Security\UrlTrustPolicy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Sajya\Client\Client as JsonRpcClient;
use Stringable;
use Throwable;
use Webong\Gouge\Ai\Support\A2a\A2aRegistry;
use Webong\Gouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\Gouge\Support\AgentEvents;
use Webong\Gouge\Support\Orchestrate\TaskRecord;
use Webong\Gouge\Support\Orchestrate\ThreadEventTaskService;

class DelegateA2aTaskTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
        protected ?ThreadActor $threadActor = null,
        protected A2aRegistry $registry = new A2aRegistry,
        protected UrlTrustPolicy $urlTrustPolicy = new UrlTrustPolicy,
    ) {}

    public function description(): Stringable|string
    {
        return 'Delegate work to a remote allowlisted A2A agent using message/send plus automatic tasks/get polling until terminal state or timeout.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        if (! $this->registry->enabled($this->thread, $this->actor)) {
            return $this->error('Outbound A2A calls are disabled.');
        }

        $agentId = trim((string) ($request['agent'] ?? ''));
        $message = trim((string) ($request['message'] ?? ''));
        $maxWaitSeconds = max(3, min(180, (int) ($request['max_wait_seconds'] ?? 25)));
        $pollIntervalMs = max(250, min(5000, (int) ($request['poll_interval_ms'] ?? 1200)));
        $cancelOnTimeout = (bool) ($request['cancel_on_timeout'] ?? false);
        $taskParams = $request['task_params'] ?? [];

        if ($agentId === '' || $message === '') {
            return $this->error('Both agent and message are required.');
        }

        if (! is_array($taskParams)) {
            return $this->error('task_params must be a JSON object.');
        }

        $agent = $this->registry->find($agentId, $this->thread, $this->actor);

        if (! is_array($agent)) {
            return $this->error('Unknown remote A2A agent.');
        }

        $trustDecision = $this->registry->trustDecision($agent);

        if (! ($trustDecision['allowed'] ?? false)) {
            $reason = (string) ($trustDecision['reason'] ?? 'Remote A2A agent endpoint URL is not allowed by policy.');
            $this->recordEvent($agentId, 'delegate.untrusted_endpoint', 'medium', $reason);

            return $this->ok([
                'ok' => false,
                'stage' => 'config',
                'agent' => $agentId,
                'error' => $reason,
            ]);
        }

        $sendPayload = [
            ...$taskParams,
            'message' => [
                'role' => 'user',
                'parts' => [
                    ['text' => $message],
                ],
            ],
        ];

        $sendResponse = $this->callAgentMethod($agent, 'message/send', $sendPayload, null);

        if (! $sendResponse['ok']) {
            $this->recordEvent($agentId, 'delegate.send_failed', 'medium', $sendResponse['error_message'] ?? 'send_failed');

            return $this->ok([
                'ok' => false,
                'stage' => 'send',
                'agent' => $agentId,
                'error' => $sendResponse,
            ]);
        }

        $task = is_array(data_get($sendResponse, 'result.task')) ? data_get($sendResponse, 'result.task') : [];
        $taskId = is_string($task['id'] ?? null) ? trim((string) $task['id']) : '';

        if ($taskId === '') {
            $this->recordEvent($agentId, 'delegate.missing_task_id', 'medium', 'Remote agent did not return task id.');

            return $this->ok([
                'ok' => false,
                'stage' => 'send',
                'agent' => $agentId,
                'error' => 'Remote agent did not return task id.',
                'send_response' => $sendResponse,
            ]);
        }

        $deadlineAt = now()->addSeconds($maxWaitSeconds);
        $lastSnapshot = null;
        $link = $this->upsertRemoteTaskLink($agentId, $taskId, $sendResponse);
        $pushRegistration = $this->registerRemotePushCallback($agent, $agentId, $taskId, $request);

        if ($link && ($pushRegistration['ok'] ?? false)) {
            $remote = is_array($link->remote) ? $link->remote : [];
            $remote['push_config_id'] = $this->trimmedString(data_get($pushRegistration, 'config.id'));
            $remote['push_registered_at'] = now()->toIso8601String();

            $link = $this->persistRemoteTaskLink(
                agentId: $agentId,
                taskId: $taskId,
                state: $link->status,
                payload: [
                    'send_response' => $sendResponse,
                ],
                existing: $link,
                remoteOverrides: $remote,
            );
        }

        while (now()->lt($deadlineAt)) {
            $snapshot = $this->callAgentMethod($agent, 'tasks/get', [
                'task_id' => $taskId,
            ], null);

            if (! $snapshot['ok']) {
                $this->recordEvent($agentId, 'delegate.poll_failed', 'medium', $snapshot['error_message'] ?? 'poll_failed', $link);

                return $this->ok([
                    'ok' => false,
                    'stage' => 'poll',
                    'agent' => $agentId,
                    'task_id' => $taskId,
                    'error' => $snapshot,
                    'send_response' => $sendResponse,
                    'push_registration' => $pushRegistration,
                ]);
            }

            $lastSnapshot = $snapshot;
            $state = strtolower((string) data_get($snapshot, 'result.task.status.state', ''));
            $link = $this->syncLinkState($link, $state, $snapshot);

            if (in_array($state, ['completed', 'failed', 'canceled'], true)) {
                $successful = $state === 'completed';

                $this->recordEvent(
                    $agentId,
                    $successful ? 'delegate.completed' : "delegate.{$state}",
                    $successful ? 'low' : 'medium',
                    null,
                    $link,
                );

                return $this->ok([
                    'ok' => $successful,
                    'stage' => 'terminal',
                    'agent' => $agentId,
                    'task_id' => $taskId,
                    'state' => $state,
                    'task' => data_get($snapshot, 'result.task'),
                    'artifacts' => data_get($snapshot, 'result.task.artifacts', []),
                    'send_response' => $sendResponse,
                    'push_registration' => $pushRegistration,
                ]);
            }

            usleep($pollIntervalMs * 1000);
        }

        $cancelResponse = null;
        if ($cancelOnTimeout) {
            $cancelResponse = $this->callAgentMethod($agent, 'tasks/cancel', [
                'task_id' => $taskId,
            ], null);
        }

        $this->recordEvent($agentId, 'delegate.timeout', 'medium', 'Delegated task timed out.', $link);

        return $this->ok([
            'ok' => false,
            'stage' => 'timeout',
            'agent' => $agentId,
            'task_id' => $taskId,
            'last_snapshot' => $lastSnapshot,
            'cancel_attempted' => $cancelOnTimeout,
            'cancel_response' => $cancelResponse,
            'send_response' => $sendResponse,
            'push_registration' => $pushRegistration,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()->required(),
            'message' => $schema->string()->required(),
            'task_params' => $schema->object(),
            'max_wait_seconds' => $schema->integer(),
            'poll_interval_ms' => $schema->integer(),
            'cancel_on_timeout' => $schema->boolean(),
            'register_push_notifications' => $schema->boolean(),
        ];
    }

    /**
     * @param  array<string, mixed>  $agent
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function callAgentMethod(array $agent, string $method, array $params, ?string $rpcId): array
    {
        $agentId = (string) ($agent['id'] ?? 'unknown');

        if (! $this->registry->isMethodAllowed($agent, $method)) {
            return [
                'ok' => false,
                'error_code' => 'a2a_method_not_allowed',
                'error_message' => 'Method is not allowlisted for this agent.',
                'agent' => $agentId,
                'method' => $method,
                'result' => null,
                'rpc_error' => null,
                'http_status' => null,
                'rpc_id' => null,
            ];
        }

        try {
            $pendingRequest = Http::baseUrl((string) $agent['endpoint'])
                ->acceptJson()
                ->asJson()
                ->timeout($this->registry->defaultTimeoutSeconds())
                ->connectTimeout(min(10, $this->registry->defaultTimeoutSeconds()))
                ->withHeaders($this->requestHeaders($agent));

            $client = new JsonRpcClient($pendingRequest);
            $response = $client->execute($method, $params, $rpcId);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error_code' => 'a2a_outbound_exception',
                'error_message' => $exception->getMessage(),
                'agent' => $agentId,
                'method' => $method,
                'result' => null,
                'rpc_error' => null,
                'http_status' => null,
                'rpc_id' => null,
            ];
        }

        $httpResponse = $response->response();
        $httpStatus = method_exists($httpResponse, 'status') ? $httpResponse->status() : null;
        $rpcError = $response->error();

        return [
            'ok' => $rpcError === null,
            'agent' => $agentId,
            'method' => $method,
            'result' => $response->result(),
            'rpc_error' => $rpcError,
            'http_status' => $httpStatus,
            'rpc_id' => $response->id(),
            'error_code' => $rpcError !== null ? 'a2a_remote_error' : null,
            'error_message' => is_array($rpcError) ? (string) ($rpcError['message'] ?? 'Remote RPC error') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $agent
     * @return array<string, string>
     */
    protected function requestHeaders(array $agent): array
    {
        $headers = is_array($agent['headers'] ?? null) ? $agent['headers'] : [];
        $authType = strtolower((string) ($agent['auth_type'] ?? 'none'));
        $token = is_string($agent['token'] ?? null) ? trim((string) $agent['token']) : '';

        if ($authType === 'bearer' && $token !== '') {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }

    protected function recordEvent(
        string $agentId,
        string $event,
        string $severity,
        ?string $errorMessage,
        ?TaskRecord $task = null
    ): void {
        $this->thread->events()->create([
            'thread_actor_id' => $this->threadActor?->id,
            'post_id' => null,
            'event_key' => 'a2a_delegate_tool',
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::A2a,
            'operation' => "delegate.{$event}",
            'state' => $this->stateForEvent($event),
            'event_type' => "a2a.{$event}",
            'severity' => $severity,
            'payload' => [
                'agent' => $agentId,
                'actor_id' => $this->actor->id,
                'actor_uuid' => $this->actor->uuid,
                'task_uuid' => $task?->uuid,
                'task_public_id' => $task?->publicId,
                'task_remote' => $task?->remote,
                'error_message' => $errorMessage !== null ? mb_substr(trim($errorMessage), 0, 500) : null,
            ],
        ]);
    }

    protected function stateForEvent(string $event): string
    {
        return match (true) {
            str_contains($event, 'failed') => ThreadEvent::StateFailed,
            str_contains($event, 'timeout') => ThreadEvent::StateFailed,
            str_contains($event, 'completed') => ThreadEvent::StateCompleted,
            default => ThreadEvent::StateRequested,
        };
    }

    /**
     * @param  array<string, mixed>  $sendResponse
     */
    protected function upsertRemoteTaskLink(string $agentId, string $taskId, array $sendResponse): ?TaskRecord
    {
        if ($agentId === '' || $taskId === '') {
            return null;
        }

        $existing = $this->taskService()
            ->latestTaskRecords()
            ->first(function (TaskRecord $task) use ($agentId, $taskId): bool {
                return $task->protocol === 'a2a'
                    && $task->thread?->is($this->thread)
                    && $task->userId === $this->actor->id
                    && data_get($task->remote, 'agent_id') === $agentId
                    && data_get($task->remote, 'task_id') === $taskId;
            });

        return $this->persistRemoteTaskLink(
            agentId: $agentId,
            taskId: $taskId,
            state: 'submitted',
            payload: [
                'send_response' => $sendResponse,
            ],
            existing: $existing,
        );
    }

    /**
     * @param  array<string, mixed>  $agent
     * @return array<string, mixed>
     */
    protected function registerRemotePushCallback(array $agent, string $agentId, string $taskId, ToolRequest $request): array
    {
        $enabled = (bool) config('a2a.outbound.push_notifications.enabled', false);
        $registerOnDelegate = (bool) config('a2a.outbound.push_notifications.register_on_delegate', true);
        $registerRequested = isset($request['register_push_notifications'])
            ? (bool) $request['register_push_notifications']
            : true;

        if (! $enabled || ! $registerOnDelegate || ! $registerRequested) {
            return [
                'ok' => false,
                'skipped' => true,
                'reason' => 'push_registration_disabled',
            ];
        }

        $callbackUrl = $this->trimmedString(config('a2a.outbound.push_notifications.callback_url'));

        if ($callbackUrl === null) {
            return [
                'ok' => false,
                'skipped' => true,
                'reason' => 'missing_callback_url',
            ];
        }

        $callbackTrust = $this->urlTrustPolicy->authorize(
            $callbackUrl,
            is_array(config('a2a.outbound.push_notifications.trust')) ? config('a2a.outbound.push_notifications.trust') : [],
        );

        if (! ($callbackTrust['allowed'] ?? false)) {
            return [
                'ok' => false,
                'skipped' => true,
                'reason' => 'untrusted_callback_url',
                'error' => (string) ($callbackTrust['reason'] ?? 'Callback URL is not allowed by policy.'),
            ];
        }

        $token = $this->trimmedString(config('a2a.outbound.push_notifications.token'));
        $stateFilter = config('a2a.outbound.push_notifications.state_filter', ['completed', 'failed', 'canceled']);
        $stateFilter = is_array($stateFilter)
            ? collect($stateFilter)->filter(fn (mixed $state): bool => is_string($state) && trim($state) !== '')->map(fn (string $state): string => strtolower(trim($state)))->values()->all()
            : ['completed', 'failed', 'canceled'];

        $headers = [
            'X-A2A-Remote-Agent' => $agentId,
            'X-A2A-Remote-Task-Id' => $taskId,
        ];

        $methods = [
            'CreateTaskPushNotificationConfig',
            'tasks/pushNotificationConfig/create',
            'tasks/pushNotificationConfig/set',
        ];

        foreach ($methods as $method) {
            if (! $this->registry->isMethodAllowed($agent, $method)) {
                continue;
            }

            $params = [
                'taskId' => $taskId,
                'task_id' => $taskId,
                'pushNotificationConfig' => [
                    'url' => $callbackUrl,
                    'token' => $token,
                    'stateFilter' => $stateFilter,
                    'headers' => $headers,
                ],
                'push_notification_config' => [
                    'url' => $callbackUrl,
                    'secret' => $token,
                    'state_filter' => $stateFilter,
                    'headers' => $headers,
                ],
            ];

            $result = $this->callAgentMethod($agent, $method, $params, null);

            if (($result['ok'] ?? false) === true) {
                return [
                    'ok' => true,
                    'method' => $method,
                    'config' => data_get($result, 'result.pushNotificationConfig')
                        ?? data_get($result, 'result.push_notification_config')
                        ?? [],
                    'raw' => $result,
                ];
            }
        }

        return [
            'ok' => false,
            'skipped' => true,
            'reason' => 'no_push_config_method_allowlisted_or_remote_failed',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function syncLinkState(?TaskRecord $link, string $state, array $snapshot): ?TaskRecord
    {
        if (! $link || $state === '') {
            return $link;
        }

        return $this->persistRemoteTaskLink(
            agentId: (string) data_get($link->remote, 'agent_id'),
            taskId: (string) data_get($link->remote, 'task_id'),
            state: $state,
            payload: $snapshot,
            existing: $link,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $remoteOverrides
     */
    protected function persistRemoteTaskLink(
        string $agentId,
        string $taskId,
        string $state,
        array $payload,
        ?TaskRecord $existing = null,
        ?array $remoteOverrides = null,
    ): ?TaskRecord {
        if ($agentId === '' || $taskId === '') {
            return null;
        }

        $remote = [
            ...(is_array($existing?->remote) ? $existing->remote : []),
            ...(is_array($remoteOverrides) ? $remoteOverrides : []),
            'agent_id' => $agentId,
            'task_id' => $taskId,
        ];

        $timestamps = [
            'completed_at' => $state === 'completed' ? ($existing?->completedAt ?? now()->toIso8601String()) : $existing?->completedAt,
            'failed_at' => $state === 'failed' ? ($existing?->failedAt ?? now()->toIso8601String()) : $existing?->failedAt,
            'canceled_at' => $state === 'canceled' ? ($existing?->canceledAt ?? now()->toIso8601String()) : $existing?->canceledAt,
        ];

        return $this->taskService()->recordSnapshot(
            thread: $this->thread,
            message: null,
            user: $this->actor,
            task: [
                'uuid' => $existing?->uuid ?? (string) Str::uuid7(),
                'public_id' => $existing?->publicId ?? $taskId,
                'status' => $state,
                'protocol' => 'a2a',
                'owner' => [
                    'subject_type' => $this->actor->getMorphClass(),
                    'subject_id' => $this->actor->getKey(),
                ],
                'remote' => $remote,
                'user_id' => $this->actor->id,
                'user_uuid' => $this->actor->uuid,
                'thread_id' => $this->thread->uuid,
                'space_id' => null,
                'snapshot' => [
                    'state' => $state,
                    'updated_at' => now()->toIso8601String(),
                ],
                'last_payload' => $payload,
                'timestamps' => array_filter($timestamps, fn (mixed $value): bool => $value !== null),
            ],
            kind: AgentEvents::A2a,
            threadActor: $this->threadActor,
        );
    }

    protected function taskService(): ThreadEventTaskService
    {
        return app(ThreadEventTaskService::class);
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
