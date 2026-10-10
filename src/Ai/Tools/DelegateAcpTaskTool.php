<?php

namespace Webong\Gouge\Ai\Tools;

use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\Gouge\Ai\Support\Acp\AcpRegistry;
use Webong\Gouge\Ai\Support\Acp\OutboundAcpClient;
use Webong\Gouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\Gouge\Support\AgentEvents;
use Webong\Gouge\Support\Orchestrate\TaskRecord;
use Webong\Gouge\Support\Orchestrate\ThreadEventTaskService;

class DelegateAcpTaskTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
        protected ?ThreadActor $threadActor = null,
        protected AcpRegistry $registry = new AcpRegistry,
        protected OutboundAcpClient $client = new OutboundAcpClient,
    ) {}

    public function description(): Stringable|string
    {
        return 'Delegate work to a registered ACP agent by initializing, opening or reusing a session, and prompting it.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        if (! $this->registry->enabled($this->thread, $this->actor)) {
            return $this->error('No ACP channels are registered for this context.');
        }

        $agentId = trim((string) ($request['agent'] ?? ''));
        $message = trim((string) ($request['message'] ?? ''));

        if ($agentId === '' || $message === '') {
            return $this->error('Both agent and message are required.');
        }

        $agent = $this->registry->find($agentId, $this->thread, $this->actor);

        if (! is_array($agent)) {
            return $this->error('Unknown remote ACP agent.');
        }

        $sessionConfig = is_array($agent['session'] ?? null) ? $agent['session'] : [];
        $timeoutSeconds = isset($request['timeout_seconds'])
            ? (int) $request['timeout_seconds']
            : $this->registry->defaultTimeoutSeconds();
        $loadAfterPrompt = isset($request['load_after_prompt'])
            ? (bool) $request['load_after_prompt']
            : (bool) ($sessionConfig['load_after_prompt'] ?? true);
        $sessionId = $this->trimmedString($request['session_id'] ?? null);
        $sessionCreateParams = $request['session_params'] ?? [];
        $promptParams = $request['prompt_params'] ?? [];
        $authenticateParams = $request['authenticate_params'] ?? [];

        if (! is_array($sessionCreateParams) || ! is_array($promptParams) || ! is_array($authenticateParams)) {
            return $this->error('session_params, prompt_params, and authenticate_params must be JSON objects.');
        }

        $bootstrap = [
            'initialize' => null,
            'authenticate' => null,
        ];

        if ($this->registry->isMethodAllowed($agent, 'initialize')) {
            $bootstrap['initialize'] = $this->client->execute(
                agent: $agent,
                method: 'initialize',
                params: $this->initializePayload($agent),
                timeoutSeconds: max(3, min(180, $timeoutSeconds)),
            );

            if (($bootstrap['initialize']['ok'] ?? false) !== true) {
                $this->recordEvent($agentId, 'bootstrap.initialize_failed', 'medium', $bootstrap['initialize']['error_message'] ?? 'initialize_failed');

                return $this->ok([
                    'ok' => false,
                    'stage' => 'initialize',
                    'agent' => $agentId,
                    'error' => $bootstrap['initialize'],
                ]);
            }
        }

        $configuredAuthenticatePayload = is_array($agent['authenticate_payload'] ?? null) ? $agent['authenticate_payload'] : [];
        $resolvedAuthenticatePayload = [
            ...$configuredAuthenticatePayload,
            ...$authenticateParams,
        ];

        if ($resolvedAuthenticatePayload !== [] && $this->registry->isMethodAllowed($agent, 'authenticate')) {
            $bootstrap['authenticate'] = $this->client->execute(
                agent: $agent,
                method: 'authenticate',
                params: $resolvedAuthenticatePayload,
                timeoutSeconds: max(3, min(180, $timeoutSeconds)),
            );

            if (($bootstrap['authenticate']['ok'] ?? false) !== true) {
                $this->recordEvent($agentId, 'bootstrap.authenticate_failed', 'medium', $bootstrap['authenticate']['error_message'] ?? 'authenticate_failed');

                return $this->ok([
                    'ok' => false,
                    'stage' => 'authenticate',
                    'agent' => $agentId,
                    'error' => $bootstrap['authenticate'],
                    'initialize' => $bootstrap['initialize'],
                ]);
            }
        }

        if ($sessionId === null && (($sessionConfig['reuse'] ?? 'thread') === 'thread')) {
            $sessionId = $this->existingRemoteSessionId($agentId);
        }

        $createdSession = null;
        if ($sessionId === null) {
            $createMethod = (string) ($sessionConfig['create_method'] ?? 'session/new');

            if (! $this->registry->isMethodAllowed($agent, $createMethod)) {
                return $this->ok([
                    'ok' => false,
                    'stage' => 'session',
                    'agent' => $agentId,
                    'error' => [
                        'error_code' => 'acp_method_not_allowed',
                        'error_message' => 'Session creation is not allowlisted for this agent.',
                        'method' => $createMethod,
                    ],
                    'initialize' => $bootstrap['initialize'],
                    'authenticate' => $bootstrap['authenticate'],
                ]);
            }

            $createdSession = $this->client->execute(
                agent: $agent,
                method: $createMethod,
                params: $this->sessionCreatePayload($agent, $sessionCreateParams),
                timeoutSeconds: max(3, min(180, $timeoutSeconds)),
            );

            if (($createdSession['ok'] ?? false) !== true) {
                $this->recordEvent($agentId, 'session.create_failed', 'medium', $createdSession['error_message'] ?? 'session_create_failed');

                return $this->ok([
                    'ok' => false,
                    'stage' => 'session',
                    'agent' => $agentId,
                    'error' => $createdSession,
                    'initialize' => $bootstrap['initialize'],
                    'authenticate' => $bootstrap['authenticate'],
                ]);
            }

            $sessionId = $this->responseSessionId($createdSession['result'] ?? null);
        }

        if ($sessionId === null) {
            return $this->ok([
                'ok' => false,
                'stage' => 'session',
                'agent' => $agentId,
                'error' => 'Remote ACP agent did not return a session id.',
                'initialize' => $bootstrap['initialize'],
                'authenticate' => $bootstrap['authenticate'],
                'session' => $createdSession,
            ]);
        }

        $promptMethod = (string) ($sessionConfig['prompt_method'] ?? 'session/prompt');

        if (! $this->registry->isMethodAllowed($agent, $promptMethod)) {
            return $this->ok([
                'ok' => false,
                'stage' => 'prompt',
                'agent' => $agentId,
                'session_id' => $sessionId,
                'error' => [
                    'error_code' => 'acp_method_not_allowed',
                    'error_message' => 'Prompting is not allowlisted for this agent.',
                    'method' => $promptMethod,
                ],
            ]);
        }

        $promptResponse = $this->client->execute(
            agent: $agent,
            method: $promptMethod,
            params: $this->promptPayload($sessionConfig, $sessionId, $message, $promptParams),
            timeoutSeconds: max(3, min(180, $timeoutSeconds)),
        );

        if (($promptResponse['ok'] ?? false) !== true) {
            $this->recordEvent($agentId, 'delegate.prompt_failed', 'medium', $promptResponse['error_message'] ?? 'prompt_failed');

            return $this->ok([
                'ok' => false,
                'stage' => 'prompt',
                'agent' => $agentId,
                'session_id' => $sessionId,
                'error' => $promptResponse,
                'initialize' => $bootstrap['initialize'],
                'authenticate' => $bootstrap['authenticate'],
                'session' => $createdSession,
            ]);
        }

        $sessionSnapshot = null;
        $loadMethod = (string) ($sessionConfig['load_method'] ?? 'session/load');
        if ($loadAfterPrompt && $this->registry->isMethodAllowed($agent, $loadMethod)) {
            $sessionSnapshot = $this->client->execute(
                agent: $agent,
                method: $loadMethod,
                params: $this->sessionLoadPayload($sessionConfig, $sessionId),
                timeoutSeconds: max(3, min(180, $timeoutSeconds)),
            );
        }

        $state = $this->responseState($promptResponse['result'] ?? null)
            ?? $this->responseState($sessionSnapshot['result'] ?? null)
            ?? 'submitted';
        $remoteTaskId = $this->responseTaskId($promptResponse['result'] ?? null)
            ?? $this->responseTaskId($sessionSnapshot['result'] ?? null)
            ?? (string) Str::ulid();

        $link = $this->upsertRemoteTaskLink(
            agentId: $agentId,
            sessionId: $sessionId,
            taskId: $remoteTaskId,
            state: $state,
            payload: [
                'initialize' => $bootstrap['initialize'],
                'authenticate' => $bootstrap['authenticate'],
                'session' => $createdSession,
                'prompt' => $promptResponse,
                'session_snapshot' => $sessionSnapshot,
            ],
        );

        $this->recordEvent(
            agentId: $agentId,
            event: $state === 'completed' ? 'completed' : 'submitted',
            severity: $state === 'completed' ? 'low' : 'medium',
            errorMessage: null,
            task: $link,
        );

        return $this->ok([
            'completed' => $state === 'completed',
            'stage' => ($link?->status ?? $state) === 'completed' ? 'terminal' : 'prompt',
            'agent' => $agentId,
            'session_id' => data_get($link?->remote, 'session_id', $sessionId),
            'task_id' => data_get($link?->remote, 'task_id', $remoteTaskId),
            'state' => $link?->status ?? $state,
            'agent_task_uuid' => $link?->uuid,
            'initialize' => $bootstrap['initialize'],
            'authenticate' => $bootstrap['authenticate'],
            'session' => $createdSession,
            'prompt_response' => $promptResponse,
            'session_snapshot' => $sessionSnapshot,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()->required(),
            'message' => $schema->string()->required(),
            'session_id' => $schema->string(),
            'timeout_seconds' => $schema->integer(),
            'load_after_prompt' => $schema->boolean(),
            'session_params' => $schema->object(),
            'prompt_params' => $schema->object(),
            'authenticate_params' => $schema->object(),
        ];
    }

    /**
     * @param  array<string, mixed>  $agent
     * @return array<string, mixed>
     */
    protected function initializePayload(array $agent): array
    {
        $client = $this->registry->client($agent);

        return [
            'client' => array_filter([
                'id' => $client['id'],
                'name' => $client['name'],
                'version' => $client['version'],
            ], fn (mixed $value): bool => is_string($value) && trim($value) !== ''),
            'capabilities' => $client['capabilities'],
            ...(is_array($agent['initialize_payload'] ?? null) ? $agent['initialize_payload'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $agent
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function sessionCreatePayload(array $agent, array $overrides): array
    {
        $space = $this->thread->threadable;
        $payload = [
            ...(is_array(data_get($agent, 'session.create_params')) ? data_get($agent, 'session.create_params') : []),
            ...$overrides,
        ];

        $payload['title'] ??= $this->thread->title;
        $payload['purpose'] ??= $this->thread->purpose;
        $payload['metadata'] = [
            ...(is_array($payload['metadata'] ?? null) ? $payload['metadata'] : []),
            'figurate' => [
                'thread_uuid' => $this->thread->uuid,
                'thread_id' => $this->thread->id,
                'space_uuid' => $space instanceof Space ? $space->uuid : null,
                'space_id' => $space instanceof Space ? $space->id : null,
                'actor_uuid' => $this->actor->uuid,
                'actor_id' => $this->actor->id,
            ],
        ];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $sessionConfig
     * @return array<string, mixed>
     */
    protected function sessionLoadPayload(array $sessionConfig, string $sessionId): array
    {
        $payload = is_array($sessionConfig['load_params'] ?? null) ? $sessionConfig['load_params'] : [];
        $payload[(string) ($sessionConfig['id_argument'] ?? 'session_id')] ??= $sessionId;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $sessionConfig
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function promptPayload(array $sessionConfig, string $sessionId, string $message, array $overrides): array
    {
        $payload = [
            ...(is_array($sessionConfig['prompt_params'] ?? null) ? $sessionConfig['prompt_params'] : []),
            ...$overrides,
        ];

        $payload[(string) ($sessionConfig['id_argument'] ?? 'session_id')] ??= $sessionId;
        $payload[(string) ($sessionConfig['prompt_argument'] ?? 'prompt')] ??= match ((string) ($sessionConfig['prompt_mode'] ?? 'string')) {
            'content_blocks' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
            default => $message,
        };

        return $payload;
    }

    protected function responseSessionId(mixed $result): ?string
    {
        foreach ([
            'session.id',
            'sessionId',
            'session_id',
            'id',
        ] as $path) {
            $value = $this->trimmedString(data_get($result, $path));

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function responseTaskId(mixed $result): ?string
    {
        foreach ([
            'task.id',
            'task_id',
            'id',
        ] as $path) {
            $value = $this->trimmedString(data_get($result, $path));

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function responseState(mixed $result): ?string
    {
        foreach ([
            'task.status.state',
            'task.state',
            'status.state',
            'state',
        ] as $path) {
            $value = $this->trimmedString(data_get($result, $path));

            if ($value !== null) {
                return strtolower($value);
            }
        }

        return null;
    }

    protected function existingRemoteSessionId(string $agentId): ?string
    {
        $task = $this->taskService()
            ->latestTaskRecords()
            ->first(function (TaskRecord $task) use ($agentId): bool {
                return $task->protocol === 'acp'
                    && $task->thread?->is($this->thread)
                    && $task->userId === $this->actor->id
                    && data_get($task->remote, 'agent_id') === $agentId
                    && is_string(data_get($task->remote, 'session_id'))
                    && trim((string) data_get($task->remote, 'session_id')) !== '';
            });

        return $this->trimmedString(data_get($task?->remote, 'session_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function upsertRemoteTaskLink(
        string $agentId,
        string $sessionId,
        string $taskId,
        string $state,
        array $payload,
    ): ?TaskRecord {
        if ($agentId === '' || $sessionId === '' || $taskId === '') {
            return null;
        }

        $existing = $this->taskService()
            ->latestTaskRecords()
            ->first(function (TaskRecord $task) use ($agentId, $sessionId, $taskId): bool {
                return $task->protocol === 'acp'
                    && $task->thread?->is($this->thread)
                    && $task->userId === $this->actor->id
                    && data_get($task->remote, 'agent_id') === $agentId
                    && (
                        data_get($task->remote, 'task_id') === $taskId
                        || data_get($task->remote, 'session_id') === $sessionId
                    );
            });

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
                'protocol' => 'acp',
                'owner' => [
                    'subject_type' => $this->actor->getMorphClass(),
                    'subject_id' => $this->actor->getKey(),
                ],
                'remote' => [
                    'protocol' => 'acp',
                    'agent_id' => $agentId,
                    'session_id' => $sessionId,
                    'task_id' => $taskId,
                ],
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
            kind: AgentEvents::Acp,
            threadActor: $this->threadActor,
        );
    }

    protected function recordEvent(
        string $agentId,
        string $event,
        string $severity,
        ?string $errorMessage,
        ?TaskRecord $task = null,
    ): void {
        $this->thread->events()->create([
            'thread_actor_id' => $this->threadActor?->id,
            'post_id' => null,
            'event_key' => 'acp_delegate_tool',
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::Acp,
            'operation' => "delegate.{$event}",
            'state' => $this->stateForEvent($event),
            'event_type' => "acp.{$event}",
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
            str_contains($event, 'completed') => ThreadEvent::StateCompleted,
            default => ThreadEvent::StateRequested,
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

    protected function taskService(): ThreadEventTaskService
    {
        return app(ThreadEventTaskService::class);
    }
}
