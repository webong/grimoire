<?php

namespace Webong\LaravelGouge\Support\Acp;

use App\Features\Actions\Chat\BootstrapConversationSpaceContext;
use App\Models\Server\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AcpMethodRouter
{
    public function __construct(
        protected AcpSessionService $sessionService,
        protected BootstrapConversationSpaceContext $bootstrapConversationSpaceContext,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function handle(User $actor, string $method, array $params): array
    {
        try {
            return match ($method) {
                'initialize' => $this->initialize($params),
                'session/new' => $this->newSession($actor, $params),
                'session/list' => $this->listSessions($actor, $params),
                'session/load' => $this->loadSession($actor, $params),
                'session/prompt' => $this->promptSession($actor, $params),
                'session/cancel' => $this->cancelSession($actor, $params),
                'tasks/get' => $this->getTask($actor, $params),
                'tasks/cancel' => $this->cancelTask($actor, $params),
                default => $this->error(-32601, 'Method not found.'),
            };
        } catch (AuthorizationException) {
            return $this->error(-32003, 'The ACP operation is not authorized.');
        } catch (ModelNotFoundException) {
            return $this->invalidParams(['resource' => ['The requested ACP resource was not found.']]);
        } catch (HttpExceptionInterface $exception) {
            return $this->invalidParams([
                'request' => [$exception->getMessage() !== '' ? $exception->getMessage() : 'The ACP request is invalid.'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(-32603, 'Internal error.');
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function initialize(array $params): array
    {
        $validation = Validator::make($params, [
            'protocolVersion' => ['required', 'integer'],
            'clientCapabilities' => ['nullable', 'array'],
            'clientInfo' => ['nullable', 'array'],
        ]);

        if ($validation->fails()) {
            return $this->invalidParams($validation->errors()->toArray());
        }

        return [
            'result' => [
                'protocolVersion' => 1,
                'agentCapabilities' => [
                    'loadSession' => true,
                    'promptCapabilities' => [
                        'image' => false,
                        'audio' => false,
                        'embeddedContext' => false,
                    ],
                    'sessionCapabilities' => [
                        'list' => new stdClass,
                    ],
                    '_meta' => [
                        'figurate.dev/async-tasks' => [
                            'getMethod' => 'tasks/get',
                            'cancelMethod' => 'tasks/cancel',
                        ],
                    ],
                ],
                'agentInfo' => [
                    'name' => 'figurate',
                    'title' => (string) config('app.name', 'Figurate'),
                    'version' => '1.0.0',
                ],
                'authMethods' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function newSession(User $actor, array $params): array
    {
        $validation = Validator::make($params, [
            'cwd' => ['required', 'string', 'max:4096', 'starts_with:/'],
            'mcpServers' => ['present', 'array'],
            '_meta' => ['nullable', 'array'],
        ]);

        if ($validation->fails()) {
            return $this->invalidParams($validation->errors()->toArray());
        }

        $spaceUuid = $this->trimmedString(
            data_get($params, '_meta.figurate.spaceId')
            ?? data_get($params, '_meta.figurate.space_id')
            ?? $params['spaceId']
            ?? $params['space_id']
            ?? null
        );

        if ($spaceUuid === null) {
            $spaceUuid = $this->bootstrapConversationSpaceContext->execute($actor)->uuid;
        }

        $session = $this->sessionService->createSession(
            actor: $actor,
            spaceUuid: $spaceUuid,
            title: $this->trimmedString(data_get($params, '_meta.figurate.title')),
            purpose: $this->trimmedString(data_get($params, '_meta.figurate.purpose')),
            phase: $this->trimmedString(data_get($params, '_meta.figurate.phase')),
        );

        return [
            'result' => [
                'sessionId' => $session['id'],
                '_meta' => [
                    'figurate' => [
                        'spaceId' => $session['space']['id'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function listSessions(User $actor, array $params): array
    {
        $validation = Validator::make($params, [
            'cwd' => ['nullable', 'string', 'max:4096'],
            'cursor' => ['nullable', 'string', 'max:4096'],
        ]);

        if ($validation->fails()) {
            return $this->invalidParams($validation->errors()->toArray());
        }

        $sessions = collect($this->sessionService->listSessions($actor))
            ->map(fn (array $session): array => [
                'sessionId' => $session['id'],
                'cwd' => '/figurate/spaces/'.$session['space']['id'],
                'title' => $session['title'],
                'updatedAt' => $session['last_message_at'],
                '_meta' => [
                    'figurate' => [
                        'spaceId' => $session['space']['id'],
                        'purpose' => $session['purpose'],
                        'status' => $session['status'],
                    ],
                ],
            ])
            ->values()
            ->all();

        return [
            'result' => [
                'sessions' => $sessions,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function loadSession(User $actor, array $params): array
    {
        $validation = Validator::make($params, [
            'sessionId' => ['required', 'uuid'],
            'cwd' => ['required', 'string', 'max:4096', 'starts_with:/'],
            'mcpServers' => ['present', 'array'],
        ]);

        if ($validation->fails()) {
            return $this->invalidParams($validation->errors()->toArray());
        }

        $this->sessionService->loadSession($actor, (string) $validation->validated()['sessionId']);

        return ['result' => null];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function promptSession(User $actor, array $params): array
    {
        $validation = Validator::make($params, [
            'sessionId' => ['required', 'uuid'],
            'prompt' => ['required', 'array', 'min:1'],
            'prompt.*.type' => ['required', 'string'],
            'prompt.*.text' => ['nullable', 'string', 'max:5000'],
            'prompt.*.uri' => ['nullable', 'string', 'max:4096'],
        ]);

        if ($validation->fails()) {
            return $this->invalidParams($validation->errors()->toArray());
        }

        $text = $this->promptText($validation->validated()['prompt']);
        if ($text === null) {
            return $this->invalidParams(['prompt' => ['At least one supported text or resource link block is required.']]);
        }

        $task = $this->sessionService->promptSession(
            actor: $actor,
            sessionUuid: (string) $validation->validated()['sessionId'],
            spaceUuid: null,
            text: $text,
        );

        return [
            'result' => [
                'stopReason' => 'end_turn',
                '_meta' => [
                    'figurate' => [
                        'asynchronous' => true,
                        'task' => $task,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function cancelSession(User $actor, array $params): array
    {
        $validation = Validator::make($params, [
            'sessionId' => ['required', 'uuid'],
        ]);

        if ($validation->fails()) {
            return $this->invalidParams($validation->errors()->toArray());
        }

        $this->sessionService->cancelSession(
            actor: $actor,
            sessionUuid: (string) $validation->validated()['sessionId'],
        );

        return ['result' => new stdClass];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function getTask(User $actor, array $params): array
    {
        $validation = Validator::make($params, [
            'taskId' => ['required', 'uuid'],
        ]);

        if ($validation->fails()) {
            return $this->invalidParams($validation->errors()->toArray());
        }

        return [
            'result' => $this->sessionService->task($actor, (string) $validation->validated()['taskId']),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function cancelTask(User $actor, array $params): array
    {
        $validation = Validator::make($params, [
            'taskId' => ['required', 'uuid'],
        ]);

        if ($validation->fails()) {
            return $this->invalidParams($validation->errors()->toArray());
        }

        return [
            'result' => $this->sessionService->cancelTask($actor, (string) $validation->validated()['taskId']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function promptText(array $blocks): ?string
    {
        $parts = collect($blocks)
            ->map(function (array $block): ?string {
                return match ($block['type'] ?? null) {
                    'text' => $this->trimmedString($block['text'] ?? null),
                    'resource_link' => $this->trimmedString($block['uri'] ?? null),
                    default => null,
                };
            })
            ->filter(fn (?string $part): bool => $part !== null)
            ->values();

        return $parts->isEmpty() ? null : $parts->implode("\n\n");
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    protected function invalidParams(array $errors): array
    {
        return $this->error(-32602, 'Invalid params.', [
            'errors' => $errors,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>
     */
    protected function error(int $code, string $message, ?array $data = null): array
    {
        return [
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'data' => $data,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    protected function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
