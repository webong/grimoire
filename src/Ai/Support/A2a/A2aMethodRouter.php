<?php

namespace Webong\LaravelGouge\Ai\Support\A2a;

use App\Contracts\Users\UserRepository;
use App\Features\Actions\Chat\ResolveConversationSpaceContext;
use App\Features\Actions\Chat\ResolveConversationThreadContext;
use App\Features\Operations\Chat\ResolveConversationThreadOperation;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Webong\LaravelGouge\Ai\Support\A2ui\A2uiCatalogRegistry;
use Webong\LaravelGouge\Ai\Support\A2ui\A2uiPayloadContract;
use Webong\LaravelGouge\Features\Actions\Chat\ResolveActiveThreadPresenters;
use Webong\LaravelGouge\Features\Operations\Chat\DispatchPromptOperation;
use Webong\LaravelGouge\Support\Orchestrate\MessageTaskService;
use Webong\LaravelGouge\Support\Orchestrate\TaskRecord;
use Webong\LaravelGouge\Support\Orchestrate\ThreadEventTaskService;

class A2aMethodRouter
{
    protected const MAX_A2UI_ACTIONS = 16;

    protected const MAX_A2UI_ERRORS = 16;

    public function __construct(
        protected ResolveConversationThreadOperation $resolveConversationThreadOperation,
        protected ResolveConversationSpaceContext $resolveConversationSpaceContext,
        protected ResolveConversationThreadContext $resolveConversationThreadContext,
        protected TaskPushNotificationDispatcher $taskPushNotificationDispatcher,
        protected A2uiPayloadContract $a2uiPayloadContract,
        protected A2uiCatalogRegistry $a2uiCatalogRegistry,
        protected DispatchPromptOperation $dispatchPromptOperation,
        protected ResolveActiveThreadPresenters $resolveActiveThreadPresenters,
        protected ThreadEventTaskService $taskService,
        protected MessageTaskService $messageTaskService,
        protected UserRepository $userRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function handle(string $method, array $params): array
    {
        return match ($method) {
            'message/send' => $this->messageSend($params),
            'tasks/get' => $this->tasksGet($params),
            'tasks/list' => $this->tasksList($params),
            'tasks/cancel' => $this->tasksCancel($params),
            'tasks/resubscribe' => $this->tasksResubscribe($params),
            'tasks/pushNotificationConfig/set' => $this->tasksPushNotificationConfigSet($params),
            'tasks/pushNotificationConfig/create' => $this->tasksPushNotificationConfigSet($params),
            'tasks/pushNotificationConfig/get' => $this->tasksPushNotificationConfigGet($params),
            'tasks/pushNotificationConfig/list' => $this->tasksPushNotificationConfigList($params),
            'tasks/pushNotificationConfig/delete' => $this->tasksPushNotificationConfigDelete($params),
            'message/stream' => $this->messageStream($params),
            default => [
                'error' => [
                    'code' => -32601,
                    'message' => 'Method not found.',
                ],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function messageSend(array $params): array
    {
        $validation = Validator::make($params, [
            'user_uuid' => ['required', 'uuid', 'exists:users,uuid'],
            'space' => ['nullable', 'uuid', 'exists:spaces,uuid'],
            'thread' => ['nullable', 'uuid', 'exists:threads,uuid'],
        ]);

        if ($validation->fails()) {
            return $this->invalidParams($validation->errors()->toArray());
        }

        $user = $this->userRepository->findByUuid((string) $validation->validated()['user_uuid']);

        if (! $user) {
            return $this->invalidParams(['user_uuid' => ['The selected user was not found.']]);
        }

        $a2uiClientCapabilities = $this->resolveA2uiClientCapabilities($params);
        $a2uiClientDataModel = $this->resolveA2uiClientDataModel($params);
        $a2uiActions = $this->resolveA2uiActions($params);
        $a2uiErrors = $this->resolveA2uiErrors($params);
        $content = $this->resolveContent($params);

        if (! is_string($content) || trim($content) === '') {
            return $this->invalidParams(['message' => ['Message text is required.']]);
        }

        $content = trim($content);
        if (mb_strlen($content) > 5000) {
            return $this->invalidParams(['message' => ['Message text may not exceed 5000 characters.']]);
        }

        $spaceUuid = $this->trimmedString($params['space'] ?? null);
        $threadUuid = $this->trimmedString($params['thread'] ?? null);
        $threadId = null;

        if ($threadUuid) {
            [$space, $threadId] = $this->resolveConversationThreadContext->execute($threadUuid, $spaceUuid);
        } else {
            $space = $this->resolveConversationSpaceContext->execute($spaceUuid, $user);
        }

        $thread = $this->resolveConversationThreadOperation->run(
            space: $space,
            actor: $user,
            thread: $threadId,
        );

        $taskId = $this->resolveTaskId($params);

        $meta = [
            'a2a_task_id' => $taskId,
            'a2a_source' => 'jsonrpc',
            'a2a_owner' => $this->resolveAuthenticatedOwner(),
        ];
        if (is_array($a2uiClientCapabilities)) {
            $meta['a2ui_client_capabilities'] = $a2uiClientCapabilities;
        }
        if ($a2uiClientDataModel !== null) {
            $meta['a2ui_client_data_model'] = $a2uiClientDataModel;
        }

        $dispatch = $this->dispatchPromptOperation->run(
            space: $space,
            thread: $thread,
            actor: $user,
            text: $content,
            options: [
                'agent_source' => 'agent_prompt',
                'direct_source' => 'peer_message',
                'dispatch_observers_when_direct' => true,
                'dispatch_observers_when_agent' => false,
                'meta' => $meta,
                'actions' => $a2uiActions !== [] ? $a2uiActions : null,
                'errors' => $a2uiErrors !== [] ? $a2uiErrors : null,
                'broadcast_space_id' => "threads.{$thread->uuid}",
            ],
        );
        $message = $dispatch['message'];
        $task = $this->taskService->createLocalTask(
            promptMessage: $message,
            user: $user,
            payload: [
                'local' => [
                    'protocol' => 'a2a',
                    'owner' => $this->resolveAuthenticatedOwner(),
                    'public_id' => $taskId,
                ],
            ],
            stateOverride: $dispatch['direct'] === true ? 'completed' : null,
        );

        if ($dispatch['direct'] === true) {
            $this->taskPushNotificationDispatcher->dispatchTaskUpdate($message, 'completed');

            return [
                'result' => $this->taskPayload(
                    taskId: $taskId,
                    state: 'completed',
                    context: [
                        'thread_id' => $thread->uuid,
                        'space_id' => $space->uuid,
                        'prompt_post_ulid' => $message->ulid,
                        'agent_task_uuid' => $task->uuid,
                    ],
                ),
            ];
        }

        $this->taskPushNotificationDispatcher->dispatchTaskUpdate($message, 'submitted');

        return [
            'result' => $this->taskPayload(
                taskId: $taskId,
                state: 'submitted',
                context: [
                    'thread_id' => $thread->uuid,
                    'space_id' => $space->uuid,
                    'prompt_post_ulid' => $message->ulid,
                    'agent_task_uuid' => $task->uuid,
                    'pending_presenters' => $dispatch['presenters']->count(),
                ],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function tasksGet(array $params): array
    {
        $taskId = $this->resolveTaskId($params);
        $task = $this->taskService->resolveOwnedA2aTask($taskId, $this->resolveAuthenticatedOwner());

        if (! $task instanceof TaskRecord) {
            return $this->invalidParams(['task_id' => ['Task was not found.']]);
        }

        $task = $this->taskService->syncLocalTask($task);
        $promptMessage = $task->message;
        if (! $promptMessage instanceof Post) {
            return $this->invalidParams(['task_id' => ['Task was not found.']]);
        }

        $snapshot = $this->messageTaskService->snapshot($promptMessage);
        $thread = $snapshot['thread'];
        $invocations = $snapshot['invocations'];
        $assistantReplies = $snapshot['assistant_replies'];
        $promptPayload = $this->messagePayload($promptMessage);
        $artifacts = $assistantReplies
            ->map(fn (Post $message): array => $this->toTaskArtifactPayload($message, $promptMessage))
            ->values()
            ->all();

        return [
            'result' => $this->taskPayload(
                taskId: $this->taskService->publicId($task),
                state: $task->status,
                context: [
                    'thread_id' => $thread?->uuid,
                    'prompt_post_ulid' => $promptMessage->ulid,
                    'prompt_post_id' => $promptMessage->id,
                    'agent_task_uuid' => $task->uuid,
                    'invocations' => $this->messageTaskService->invocationPayload($invocations),
                    'payload' => $promptPayload,
                ],
                artifacts: $artifacts,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function tasksList(array $params): array
    {
        $limit = max(1, min(100, (int) ($params['maxItems'] ?? $params['limit'] ?? 20)));
        $userUuid = $this->trimmedString($params['user_uuid'] ?? null);

        $tasks = $this->taskService->listOwnedA2aTasks($this->resolveAuthenticatedOwner(), $userUuid, $limit)
            ->map(function (TaskRecord $task): array {
                $task = $this->taskService->syncLocalTask($task);
                $promptMessage = $task->message;
                $thread = $task->thread;

                return [
                    'id' => $this->taskService->publicId($task),
                    'kind' => 'task',
                    'status' => [
                        'state' => $task->status,
                        'timestamp' => now()->toIso8601String(),
                    ],
                    'context' => [
                        'thread_id' => $thread?->uuid,
                        'prompt_post_ulid' => $promptMessage?->ulid,
                        'agent_task_uuid' => $task->uuid,
                    ],
                ];
            })
            ->values()
            ->all();

        return [
            'result' => [
                'tasks' => $tasks,
                'nextPageToken' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function tasksCancel(array $params): array
    {
        $taskId = $this->resolveTaskId($params);
        $task = $this->taskService->resolveOwnedA2aTask($taskId, $this->resolveAuthenticatedOwner());

        if (! $task instanceof TaskRecord) {
            return $this->invalidParams(['task_id' => ['Task was not found.']]);
        }

        $promptMessage = $task->message;
        if (! $promptMessage instanceof Post) {
            return $this->invalidParams(['task_id' => ['Task was not found.']]);
        }

        $thread = $this->messageTaskService->resolveMessageThread($promptMessage);
        $task = $this->taskService->cancelLocalTask(
            task: $task,
            presenters: $thread instanceof Thread ? $this->resolveActiveThreadPresenters->execute($thread) : collect(),
            canceledMetaPath: 'a2a_canceled_at',
        );
        $promptMessage = $task->message;
        if (! $promptMessage instanceof Post) {
            return $this->invalidParams(['task_id' => ['Task was not found.']]);
        }
        $this->taskPushNotificationDispatcher->dispatchTaskUpdate($promptMessage, 'canceled');

        return [
            'result' => $this->taskPayload(
                taskId: $this->taskService->publicId($task),
                state: $task->status,
                context: [
                    'prompt_post_ulid' => $promptMessage->ulid,
                    'agent_task_uuid' => $task->uuid,
                ],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function tasksResubscribe(array $params): array
    {
        $taskId = $this->resolveTaskId($params);
        $taskSnapshot = $this->tasksGet([
            ...$params,
            'task_id' => $taskId,
        ]);

        if (is_array($taskSnapshot['error'] ?? null)) {
            return $taskSnapshot;
        }

        $result = is_array($taskSnapshot['result'] ?? null) ? $taskSnapshot['result'] : [];
        $task = is_array($result['task'] ?? null) ? $result['task'] : [];

        return [
            'result' => [
                'task' => $task,
                'streaming' => [
                    'enabled' => true,
                    'transport' => 'sse',
                    'space' => '/api/a2a/stream',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function messageStream(array $params): array
    {
        $sendResult = $this->messageSend($params);

        if (is_array($sendResult['error'] ?? null)) {
            return $sendResult;
        }

        $taskPayload = is_array($sendResult['result']['task'] ?? null) ? $sendResult['result']['task'] : [];

        return [
            'result' => [
                'task' => $taskPayload,
                'stream' => [
                    'transport' => 'sse',
                    'space' => '/api/a2a/stream',
                    'status' => 'streaming_prepared',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function tasksPushNotificationConfigSet(array $params): array
    {
        $taskId = $this->resolveTaskId($params);
        $promptMessage = $this->resolvePromptMessage($taskId);

        if (! $promptMessage) {
            return $this->invalidParams(['task_id' => ['Task was not found.']]);
        }

        $config = is_array($params['pushNotificationConfig'] ?? null)
            ? $params['pushNotificationConfig']
            : (is_array($params['push_notification_config'] ?? null) ? $params['push_notification_config'] : $params);
        $url = $this->trimmedString($config['url'] ?? $config['endpoint'] ?? null);

        if ($url === null) {
            return $this->invalidParams(['url' => ['Push notification URL is required.']]);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->invalidParams(['url' => ['Push notification URL must be a valid URL.']]);
        }

        $token = $this->trimmedString($config['token'] ?? null)
            ?? $this->trimmedString(data_get($config, 'authentication.credentials'));

        $normalizedConfig = [
            'id' => $this->trimmedString($config['id'] ?? null) ?? (string) Str::uuid7(),
            'url' => $url,
            'secret' => $token ?? $this->trimmedString($config['secret'] ?? null),
            'headers' => $this->normalizeHeaders($config['headers'] ?? config('a2a.inbound.push_notifications.default_headers', [])),
            'state_filter' => $this->normalizeStates($config['stateFilter'] ?? $config['state_filter'] ?? $config['states'] ?? config('a2a.inbound.push_notifications.default_state_filter', [])),
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        $meta = is_array($promptMessage->meta) ? $promptMessage->meta : [];
        $configs = is_array(data_get($meta, 'a2a.push_notification_configs')) ? data_get($meta, 'a2a.push_notification_configs') : [];
        $existingIndex = collect($configs)->search(fn (mixed $entry): bool => is_array($entry) && ($entry['id'] ?? null) === $normalizedConfig['id']);

        if (is_int($existingIndex)) {
            $existing = is_array($configs[$existingIndex] ?? null) ? $configs[$existingIndex] : [];
            $normalizedConfig['created_at'] = $existing['created_at'] ?? $normalizedConfig['created_at'];
            $configs[$existingIndex] = $normalizedConfig;
        } else {
            $configs[] = $normalizedConfig;
        }

        data_set($meta, 'a2a.push_notification_configs', array_values($configs));
        $promptMessage->forceFill(['meta' => $meta])->save();

        return [
            'result' => [
                'taskId' => $taskId,
                'task_id' => $taskId,
                'pushNotificationConfig' => $this->toPushNotificationConfig($normalizedConfig),
                'push_notification_config' => $normalizedConfig,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function tasksPushNotificationConfigGet(array $params): array
    {
        $taskId = $this->resolveTaskId($params);
        $promptMessage = $this->resolvePromptMessage($taskId);

        if (! $promptMessage) {
            return $this->invalidParams(['task_id' => ['Task was not found.']]);
        }

        $configId = $this->trimmedString($params['pushNotificationConfigId'] ?? $params['push_notification_config_id'] ?? null);
        $configs = $this->pushConfigs($promptMessage);

        if ($configId === null) {
            $first = $configs[0] ?? null;

            if (! is_array($first)) {
                return $this->invalidParams(['push_notification_config_id' => ['Push notification config was not found.']]);
            }

            return [
                'result' => [
                    'taskId' => $taskId,
                    'task_id' => $taskId,
                    'pushNotificationConfig' => $this->toPushNotificationConfig($first),
                    'push_notification_config' => $first,
                ],
            ];
        }

        $resolved = collect($configs)
            ->first(fn (mixed $entry): bool => is_array($entry) && ($entry['id'] ?? null) === $configId);

        if (! is_array($resolved)) {
            return $this->invalidParams(['push_notification_config_id' => ['Push notification config was not found.']]);
        }

        return [
            'result' => [
                'taskId' => $taskId,
                'task_id' => $taskId,
                'pushNotificationConfig' => $this->toPushNotificationConfig($resolved),
                'push_notification_config' => $resolved,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function tasksPushNotificationConfigList(array $params): array
    {
        $taskId = $this->resolveTaskId($params);
        $promptMessage = $this->resolvePromptMessage($taskId);

        if (! $promptMessage) {
            return $this->invalidParams(['task_id' => ['Task was not found.']]);
        }

        return [
            'result' => [
                'taskId' => $taskId,
                'task_id' => $taskId,
                'pushNotificationConfigs' => collect($this->pushConfigs($promptMessage))
                    ->map(fn (array $config): array => $this->toPushNotificationConfig($config))
                    ->values()
                    ->all(),
                'push_notification_configs' => $this->pushConfigs($promptMessage),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function tasksPushNotificationConfigDelete(array $params): array
    {
        $taskId = $this->resolveTaskId($params);
        $promptMessage = $this->resolvePromptMessage($taskId);

        if (! $promptMessage) {
            return $this->invalidParams(['task_id' => ['Task was not found.']]);
        }

        $configId = $this->trimmedString($params['pushNotificationConfigId'] ?? $params['push_notification_config_id'] ?? null);

        if ($configId === null) {
            return $this->invalidParams(['push_notification_config_id' => ['Push notification config id is required.']]);
        }

        $meta = is_array($promptMessage->meta) ? $promptMessage->meta : [];
        $configs = $this->pushConfigs($promptMessage);

        $updatedConfigs = collect($configs)
            ->filter(fn (mixed $entry): bool => ! (is_array($entry) && ($entry['id'] ?? null) === $configId))
            ->values()
            ->all();

        data_set($meta, 'a2a.push_notification_configs', $updatedConfigs);
        $promptMessage->forceFill(['meta' => $meta])->save();

        return [
            'result' => [
                'taskId' => $taskId,
                'task_id' => $taskId,
                'deleted' => count($updatedConfigs) !== count($configs),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function resolveTaskId(array $params): string
    {
        $taskId = $params['id'] ?? $params['taskId'] ?? $params['task_id'] ?? null;

        if (! is_string($taskId) || trim($taskId) === '') {
            return (string) Str::uuid7();
        }

        return trim($taskId);
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @param  array<int, array<string, mixed>>  $artifacts
     * @return array<string, mixed>
     */
    protected function taskPayload(
        string $taskId,
        string $state,
        ?array $context = null,
        array $artifacts = [],
    ): array {
        return [
            'task' => [
                'id' => $taskId,
                'kind' => 'task',
                'status' => [
                    'state' => $state,
                    'timestamp' => now()->toIso8601String(),
                ],
                'context' => $context ?? [],
                'artifacts' => $artifacts,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    protected function invalidParams(array $errors): array
    {
        return [
            'error' => [
                'code' => -32602,
                'message' => 'Invalid params.',
                'data' => [
                    'errors' => $errors,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function resolveContent(array $params): ?string
    {
        $direct = $this->trimmedString(data_get($params, 'content.text'));

        if ($direct) {
            return $direct;
        }

        $message = $params['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        $parts = $message['parts'] ?? [];
        if (! is_array($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $text = $this->trimmedString($part['text'] ?? null);
            if ($text) {
                return $text;
            }

            $a2uiSummary = $this->resolveA2uiSummaryFromPart($part);
            if ($a2uiSummary !== null) {
                return $a2uiSummary;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $part
     */
    protected function resolveA2uiSummaryFromPart(array $part): ?string
    {
        if (! $this->isA2uiDataPart($part)) {
            return null;
        }

        $payload = $part['data'] ?? null;

        if (! is_array($payload)) {
            return null;
        }

        if ($this->isAssoc($payload)) {
            $summary = $this->resolveA2uiSummaryFromEntry($payload);
            if ($summary !== null) {
                return $summary;
            }
        }

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $summary = $this->resolveA2uiSummaryFromEntry($entry);

            if ($summary !== null) {
                return $summary;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function resolveA2uiSummaryFromEntry(array $entry): ?string
    {
        $action = $entry['userAction'] ?? $entry['action'] ?? null;
        if (is_array($action)) {
            $normalizedAction = $this->normalizeA2uiAction($action);
            $actionName = $this->trimmedString($normalizedAction['name'] ?? null);

            if ($actionName !== null) {
                return "A2UI action: {$actionName}";
            }

            return 'A2UI action submitted.';
        }

        $error = $entry['error'] ?? null;
        if (is_array($error)) {
            $normalizedError = $this->normalizeA2uiError($error);
            $errorMessage = $this->trimmedString($normalizedError['message'] ?? null);
            $errorCode = $this->trimmedString($normalizedError['code'] ?? null);

            if ($errorMessage !== null) {
                return "A2UI error: {$errorMessage}";
            }

            if ($errorCode !== null) {
                return "A2UI error code: {$errorCode}";
            }

            return 'A2UI error reported.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    protected function resolveA2uiClientCapabilities(array $params): ?array
    {
        $capabilities = data_get($params, 'metadata.a2uiClientCapabilities');

        if (! is_array($capabilities)) {
            $capabilities = data_get($params, 'message.metadata.a2uiClientCapabilities');
        }

        if (! is_array($capabilities)) {
            $capabilities = data_get($params, 'extra.a2ui.config.a2uiClientCapabilities');
        }

        return $this->a2uiPayloadContract->normalizeClientCapabilities(
            is_array($capabilities) ? $capabilities : null
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function resolveA2uiClientDataModel(array $params): ?string
    {
        $model = $this->trimmedString(
            data_get($params, 'metadata.a2uiClientDataModel')
            ?? data_get($params, 'message.metadata.a2uiClientDataModel')
            ?? data_get($params, 'extra.a2ui.config.a2uiClientDataModel')
        );

        return $model;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    protected function resolveA2uiActions(array $params): array
    {
        $actions = collect(data_get($params, 'content.actions', []))
            ->map(fn (mixed $action): ?array => is_array($action) ? $this->normalizeA2uiAction($action) : null)
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values()
            ->all();
        $actions = $this->dedupeA2uiActions($actions);

        if (count($actions) >= self::MAX_A2UI_ACTIONS) {
            return array_slice($actions, 0, self::MAX_A2UI_ACTIONS);
        }

        $parts = data_get($params, 'message.parts');

        if (! is_array($parts)) {
            return array_slice($actions, 0, self::MAX_A2UI_ACTIONS);
        }

        foreach ($parts as $part) {
            if (! is_array($part) || ! $this->isA2uiDataPart($part)) {
                continue;
            }

            $payload = $part['data'] ?? null;

            if (! is_array($payload)) {
                continue;
            }

            if ($this->isAssoc($payload)) {
                $entryActions = $this->resolveA2uiActionsFromEntry($payload);
                $actions = [...$actions, ...$entryActions];
                $actions = $this->dedupeA2uiActions($actions);

                if (count($actions) >= self::MAX_A2UI_ACTIONS) {
                    break;
                }

                continue;
            }

            foreach ($payload as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entryActions = $this->resolveA2uiActionsFromEntry($entry);
                $actions = [...$actions, ...$entryActions];
                $actions = $this->dedupeA2uiActions($actions);

                if (count($actions) >= self::MAX_A2UI_ACTIONS) {
                    break;
                }
            }

            if (count($actions) >= self::MAX_A2UI_ACTIONS) {
                break;
            }
        }

        return array_slice(
            collect($actions)
                ->filter(fn (mixed $entry): bool => is_array($entry))
                ->values()
                ->all(),
            0,
            self::MAX_A2UI_ACTIONS
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    protected function resolveA2uiErrors(array $params): array
    {
        $errors = collect(data_get($params, 'content.errors', []))
            ->map(fn (mixed $error): ?array => is_array($error) ? $this->normalizeA2uiError($error) : null)
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values()
            ->all();
        $errors = $this->dedupeA2uiErrors($errors);

        if (count($errors) >= self::MAX_A2UI_ERRORS) {
            return array_slice($errors, 0, self::MAX_A2UI_ERRORS);
        }

        $parts = data_get($params, 'message.parts');

        if (! is_array($parts)) {
            return array_slice($errors, 0, self::MAX_A2UI_ERRORS);
        }

        foreach ($parts as $part) {
            if (! is_array($part) || ! $this->isA2uiDataPart($part)) {
                continue;
            }

            $payload = $part['data'] ?? null;

            if (! is_array($payload)) {
                continue;
            }

            if ($this->isAssoc($payload)) {
                $entryErrors = $this->resolveA2uiErrorsFromEntry($payload);
                $errors = [...$errors, ...$entryErrors];
                $errors = $this->dedupeA2uiErrors($errors);

                if (count($errors) >= self::MAX_A2UI_ERRORS) {
                    break;
                }

                continue;
            }

            foreach ($payload as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entryErrors = $this->resolveA2uiErrorsFromEntry($entry);
                $errors = [...$errors, ...$entryErrors];
                $errors = $this->dedupeA2uiErrors($errors);

                if (count($errors) >= self::MAX_A2UI_ERRORS) {
                    break;
                }
            }

            if (count($errors) >= self::MAX_A2UI_ERRORS) {
                break;
            }
        }

        return array_slice(
            collect($errors)
                ->filter(fn (mixed $entry): bool => is_array($entry))
                ->values()
                ->all(),
            0,
            self::MAX_A2UI_ERRORS
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, array<string, mixed>>
     */
    protected function resolveA2uiActionsFromEntry(array $entry): array
    {
        $actions = [];

        $action = $entry['userAction'] ?? $entry['action'] ?? null;

        if (is_array($action)) {
            $normalizedAction = $this->normalizeA2uiAction($action);
            if (is_array($normalizedAction)) {
                $actions[] = $normalizedAction;
            }
        } elseif ($this->looksLikeAction($entry)) {
            $normalizedAction = $this->normalizeA2uiAction($entry);
            if (is_array($normalizedAction)) {
                $actions[] = $normalizedAction;
            }
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, array<string, mixed>>
     */
    protected function resolveA2uiErrorsFromEntry(array $entry): array
    {
        $errors = [];
        $error = $entry['error'] ?? null;

        if (! is_array($error)) {
            return $errors;
        }

        $normalizedError = $this->normalizeA2uiError($error);
        if (is_array($normalizedError)) {
            $errors[] = $normalizedError;
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>|null
     */
    protected function normalizeA2uiAction(array $action): ?array
    {
        return $this->a2uiPayloadContract->normalizeAction($action);
    }

    /**
     * @param  array<string, mixed>  $error
     * @return array<string, mixed>|null
     */
    protected function normalizeA2uiError(array $error): ?array
    {
        return $this->a2uiPayloadContract->normalizeError($error);
    }

    /**
     * @param  array<string, mixed>  $part
     */
    protected function isA2uiDataPart(array $part): bool
    {
        $kind = $this->trimmedString($part['kind'] ?? null);
        $mimeType = $this->trimmedString(
            data_get($part, 'metadata.mimeType')
            ?? data_get($part, 'metadata.mimetype')
            ?? data_get($part, 'metadata.contentType')
            ?? data_get($part, 'metadata.content_type')
        );

        if ($kind !== 'data') {
            return false;
        }

        if ($mimeType === null) {
            return false;
        }

        return $this->matchesA2uiMimeType($mimeType);
    }

    protected function matchesA2uiMimeType(string $mimeType): bool
    {
        $normalized = strtolower(trim($mimeType));
        $normalized = explode(';', $normalized, 2)[0] ?? $normalized;
        $normalized = trim($normalized);

        return $normalized === 'application/json+a2ui';
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<int, array<string, mixed>>
     */
    protected function dedupeA2uiActions(array $actions): array
    {
        $seen = [];
        $deduped = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $key = implode('|', [
                $this->trimmedString($action['protocol'] ?? null) ?? 'a2ui',
                $this->trimmedString($action['name'] ?? null) ?? '',
                $this->trimmedString($action['id'] ?? null) ?? '',
                $this->trimmedString($action['surfaceId'] ?? null) ?? '',
                $this->trimmedString($action['timestamp'] ?? null) ?? '',
            ]);

            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $action;
        }

        return $deduped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @return array<int, array<string, mixed>>
     */
    protected function dedupeA2uiErrors(array $errors): array
    {
        $seen = [];
        $deduped = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $key = implode('|', [
                $this->trimmedString($error['protocol'] ?? null) ?? 'a2ui',
                $this->trimmedString($error['code'] ?? null) ?? '',
                $this->trimmedString($error['path'] ?? null) ?? '',
                $this->trimmedString($error['message'] ?? null) ?? '',
            ]);

            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $error;
        }

        return $deduped;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function isAssoc(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function looksLikeAction(array $value): bool
    {
        return $this->a2uiPayloadContract->looksLikeAction($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function messagePayload(Post $message): ?array
    {
        $surface = data_get($message->meta, 'a2ui');
        $surface = is_array($surface) ? $surface : null;
        $actions = is_array($message->actions) ? $message->actions : [];
        $errors = is_array($message->errors) ? $message->errors : [];
        $dataModel = $this->trimmedString(data_get($message->meta, 'a2ui_client_data_model'));
        $capabilities = $this->a2uiPayloadContract->normalizeClientCapabilities(
            is_array(data_get($message->meta, 'a2ui_client_capabilities'))
                ? data_get($message->meta, 'a2ui_client_capabilities')
                : null
        );

        if ($surface === null && $actions === [] && $errors === [] && $dataModel === null && $capabilities === null) {
            return null;
        }

        if (is_array($surface)) {
            $surface = $this->a2uiCatalogRegistry->decoratePayload($surface, $capabilities);
        }

        return [
            'protocol' => 'a2ui',
            'surface' => $surface,
            'actions' => $actions,
            'errors' => $errors,
            'config' => [
                'a2uiClientDataModel' => $dataModel,
                'a2uiClientCapabilities' => $capabilities,
            ],
            'data_model' => $dataModel,
            'capabilities' => $capabilities,
        ];
    }

    protected function toTaskArtifactPayload(Post $message, Post $promptMessage): array
    {
        $text = is_string($message->text) ? trim($message->text) : '';

        $artifact = [
            'id' => $message->ulid,
            'kind' => $text !== '' ? 'text' : 'data',
            'actor_key' => data_get($message->meta, 'actor_key'),
            'created_at' => optional($message->created_at)?->toIso8601String(),
        ];

        if ($text !== '') {
            $artifact['text'] = $text;
        }

        $dataModel = $this->trimmedString(data_get($promptMessage->meta, 'a2ui_client_data_model'))
            ?? $this->trimmedString(data_get($message->meta, 'a2ui_client_data_model'));
        $capabilities = $this->a2uiPayloadContract->normalizeClientCapabilities(
            is_array(data_get($promptMessage->meta, 'a2ui_client_capabilities'))
                ? data_get($promptMessage->meta, 'a2ui_client_capabilities')
                : (is_array(data_get($message->meta, 'a2ui_client_capabilities'))
                    ? data_get($message->meta, 'a2ui_client_capabilities')
                    : null)
        );
        $a2uiPayload = $this->resolveA2uiAssistantPayload($message, $capabilities);

        if (is_array($a2uiPayload)) {
            $artifact['parts'] = [[
                'kind' => 'data',
                'data' => $a2uiPayload,
                'metadata' => [
                    'mimeType' => 'application/json+a2ui',
                    'protocol' => 'a2ui',
                    'config' => [
                        'a2uiClientDataModel' => $dataModel,
                        'a2uiClientCapabilities' => $capabilities,
                    ],
                ],
            ]];
        }

        return $artifact;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveA2uiAssistantPayload(Post $message, ?array $clientCapabilities = null): ?array
    {
        $payload = data_get($message->meta, 'a2ui');

        if (is_array($payload)) {
            return $this->a2uiCatalogRegistry->decoratePayload($payload, $clientCapabilities);
        }

        return null;
    }

    protected function resolvePromptMessage(string $taskId): ?Post
    {
        $promptMessage = Post::query()
            ->messageType()
            ->where(function ($query) use ($taskId): void {
                $query->where('meta->a2a_task_id', $taskId)
                    ->orWhere('ulid', $taskId);
            })
            ->whereIn('meta->source', ['agent_prompt', 'peer_message'])
            ->latest('id')
            ->first();

        if (! $promptMessage) {
            return null;
        }

        return $this->ownsTask($promptMessage) ? $promptMessage : null;
    }

    /**
     * @return array{subject_type: string, subject_id: int|string, token_id: int|null}|null
     */
    protected function resolveAuthenticatedOwner(): ?array
    {
        $request = request();
        $principal = $request->user()
            ?? auth()->user()
            ?? auth('sanctum')->user()
            ?? auth('passport')->user();

        if (! $principal instanceof Authenticatable) {
            return null;
        }

        $subjectType = method_exists($principal, 'getMorphClass')
            ? $principal->getMorphClass()
            : get_class($principal);
        $subjectId = $principal->getAuthIdentifier();

        if (! is_string($subjectType) || $subjectType === '' || (! is_int($subjectId) && ! is_string($subjectId))) {
            return null;
        }

        $token = method_exists($principal, 'currentAccessToken')
            ? $principal->currentAccessToken()
            : null;

        return [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'token_id' => is_object($token) && isset($token->id) ? (int) $token->id : null,
        ];
    }

    protected function ownsTask(Post $promptMessage): bool
    {
        $owner = $this->resolveAuthenticatedOwner();
        $taskOwner = data_get($promptMessage->meta, 'a2a_owner');

        if (! is_array($owner) || ! is_array($taskOwner)) {
            return false;
        }

        $taskOwnerType = $this->trimmedString($taskOwner['subject_type'] ?? null);
        $taskOwnerId = $taskOwner['subject_id'] ?? null;

        if ($taskOwnerType === null || (! is_int($taskOwnerId) && ! is_string($taskOwnerId))) {
            return false;
        }

        return $owner['subject_type'] === $taskOwnerType
            && (string) $owner['subject_id'] === (string) $taskOwnerId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function pushConfigs(Post $promptMessage): array
    {
        $configs = data_get($promptMessage->meta, 'a2a.push_notification_configs');

        if (! is_array($configs)) {
            return [];
        }

        return collect($configs)
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values()
            ->all();
    }

    protected function resolveMessageThread(Post $message): ?Thread
    {
        return $this->messageTaskService->resolveMessageThread($message);
    }

    /**
     * @return Collection<int, Post>
     */
    protected function assistantRepliesForPrompt(?Thread $thread, Post $promptMessage): Collection
    {
        return $this->messageTaskService->assistantRepliesForPrompt($thread, $promptMessage);
    }

    /**
     * @param  array<string, mixed>  $invocations
     * @param  Collection<int, Post>  $assistantReplies
     */
    protected function resolveTaskState(array $invocations, Collection $assistantReplies): string
    {
        return $this->messageTaskService->resolveTaskState($invocations, $assistantReplies);
    }

    protected function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeHeaders(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $normalized = [];

        foreach ($headers as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            $normalizedKey = trim($key);

            if ($normalizedKey === '') {
                continue;
            }

            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    protected function normalizeStates(mixed $states): array
    {
        if (! is_array($states)) {
            return [];
        }

        return collect($states)
            ->filter(fn (mixed $state): bool => is_string($state) && trim($state) !== '')
            ->map(fn (string $state): string => strtolower(trim($state)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function toPushNotificationConfig(array $config): array
    {
        return [
            'id' => $config['id'] ?? null,
            'url' => $config['url'] ?? null,
            'token' => $config['secret'] ?? null,
            'authentication' => [
                'schemes' => ['bearer'],
                'credentials' => $config['secret'] ?? null,
            ],
            'headers' => is_array($config['headers'] ?? null) ? $config['headers'] : [],
            'stateFilter' => is_array($config['state_filter'] ?? null) ? $config['state_filter'] : [],
            'state_filter' => is_array($config['state_filter'] ?? null) ? $config['state_filter'] : [],
            'createdAt' => $config['created_at'] ?? null,
            'created_at' => $config['created_at'] ?? null,
            'updatedAt' => $config['updated_at'] ?? null,
            'updated_at' => $config['updated_at'] ?? null,
        ];
    }
}
