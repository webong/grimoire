<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Webong\LaravelGouge\Ai\Support\SubAgents\SubAgentDispatcher;
use Webong\LaravelGouge\Ai\Support\SubAgents\SubAgentInvocationMemory;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\LaravelGouge\Models\Server\AgentConversationMessage;
use Webong\LaravelGouge\Models\Server\ThreadActorSession;
use Webong\LaravelGouge\Support\AgentEvents;

class InvokeSubAgentTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
        protected ?ThreadActor $threadActor = null,
        protected SubAgentDispatcher $dispatcher = new SubAgentDispatcher,
        protected SubAgentInvocationMemory $invocationMemory = new SubAgentInvocationMemory,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Invoke a local in-process sub-agent and return response with trace and invocation identifiers.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $subAgent = trim((string) ($request['sub_agent'] ?? ''));
        $prompt = trim((string) ($request['prompt'] ?? ''));
        $context = $request['context'] ?? [];
        $traceId = $this->normalizedString($request['trace_id'] ?? null);
        $parentInvocationId = $this->normalizedString($request['parent_invocation_id'] ?? null);
        $memory = $this->readInvocationMemory();

        if ($subAgent === '' || $prompt === '') {
            return $this->error('Both sub_agent and prompt are required.');
        }

        if (! is_array($context)) {
            return $this->error('context must be a JSON object.');
        }

        if (! $this->isSubAgentAllowedForActor($subAgent)) {
            return $this->ok([
                'ok' => false,
                'error_code' => 'sub_agent_not_allowed',
                'error' => 'The requested sub-agent is not allowed for this actor.',
                'sub_agent' => $subAgent,
            ]);
        }

        $resolvedParentInvocationId = $this->resolveParentInvocationId($parentInvocationId, $memory['parent_invocation_id'] ?? null);
        $resolvedTraceId = $this->resolveTraceId($traceId, $resolvedParentInvocationId, $memory['trace_id'] ?? null);

        $limitFailure = $this->enforcePolicyLimits($subAgent, $resolvedTraceId);
        if (is_array($limitFailure)) {
            return $this->ok($limitFailure);
        }

        $result = $this->dispatcher->dispatch(
            subAgentKey: $subAgent,
            prompt: $prompt,
            context: $context,
            traceId: $resolvedTraceId,
            parentInvocationId: $resolvedParentInvocationId,
        );

        $successful = (bool) ($result['ok'] ?? false);

        if ($successful) {
            $this->persistSuccessfulInvocation($subAgent, $result);
        }

        unset($result['telemetry']);

        $this->rememberInvocationContext(
            traceId: $resolvedTraceId,
            parentInvocationId: $resolvedParentInvocationId,
            lastSubAgentInvocationId: $this->normalizedString($result['invocation_id'] ?? null),
        );
        $this->recordInvocationEvent($subAgent, $result, $successful);

        return json_encode($result, JSON_UNESCAPED_SLASHES)
            ?: '{"ok":false,"error":"failed_to_encode_sub_agent_response"}';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sub_agent' => $schema->string()->required(),
            'prompt' => $schema->string()->required(),
            'context' => $schema->object(),
            'trace_id' => $schema->string(),
            'parent_invocation_id' => $schema->string(),
        ];
    }

    protected function recordInvocationEvent(string $subAgent, array $result, bool $successful): void
    {
        $this->thread->events()->create([
            'thread_actor_id' => $this->threadActor?->id,
            'post_id' => $this->resolveInvocationPostId($this->normalizedString($result['parent_invocation_id'] ?? null)),
            'event_key' => 'sub_agent_invoke_tool',
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::Orchestration,
            'operation' => "sub_agent.{$subAgent}",
            'state' => $successful ? ThreadEvent::StateCompleted : ThreadEvent::StateFailed,
            'event_type' => $successful ? 'sub_agent.invocation.success' : 'sub_agent.invocation.failure',
            'severity' => $successful ? 'low' : 'medium',
            'payload' => [
                'sub_agent' => $subAgent,
                'trace_id' => $result['trace_id'] ?? null,
                'invocation_id' => $result['invocation_id'] ?? null,
                'parent_invocation_id' => $result['parent_invocation_id'] ?? null,
                'actor_id' => $this->actor->id,
                'actor_uuid' => $this->actor->uuid,
                'error_code' => $result['error_code'] ?? null,
                'error_message' => ! $successful && is_string($result['error'] ?? null)
                    ? mb_substr(trim((string) $result['error']), 0, 500)
                    : null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function persistSuccessfulInvocation(string $subAgent, array $result): void
    {
        $invocationId = $this->normalizedString($result['invocation_id'] ?? null);
        $traceId = $this->normalizedString($result['trace_id'] ?? null);
        $parentInvocationId = $this->normalizedString($result['parent_invocation_id'] ?? null);
        $responseText = $this->normalizedString(data_get($result, 'response.text'));
        $telemetry = is_array($result['telemetry'] ?? null) ? $result['telemetry'] : [];
        $conversationId = $this->resolveConversationId();
        $invocable = $this->resolveInvocable($parentInvocationId);

        if ($invocationId === null || $responseText === null || $conversationId === null) {
            return;
        }

        $message = AgentConversationMessage::query()
            ->where('invocation_id', $invocationId)
            ->first() ?? new AgentConversationMessage;
        $message->forceFill([
            'id' => $message->exists ? $message->id : (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => $this->actor->getMorphClass(),
            'participant_id' => $this->actor->id,
            'agent' => $this->normalizedString($telemetry['agent'] ?? null) ?? $subAgent,
            'role' => 'assistant',
            'invocation_id' => $invocationId,
            'trace_id' => $traceId ?? $invocationId,
            'parent_invocation_id' => $parentInvocationId,
            'invocable_type' => $invocable?->getMorphClass(),
            'invocable_id' => $invocable?->getKey(),
            'content' => $responseText,
            'attachments' => '[]',
            'tool_calls' => json_encode(is_array($telemetry['tool_calls'] ?? null) ? $telemetry['tool_calls'] : []),
            'tool_results' => json_encode(is_array($telemetry['tool_results'] ?? null) ? $telemetry['tool_results'] : []),
            'usage' => json_encode(is_array($telemetry['usage'] ?? null) ? $telemetry['usage'] : []),
            'meta' => json_encode([
                ...(is_array($telemetry['meta'] ?? null) ? $telemetry['meta'] : []),
                'kind' => 'sub_agent',
                'sub_agent' => $subAgent,
                'provider_invocation_id' => $this->normalizedString(data_get($result, 'response.provider_invocation_id')),
            ]),
            'approval_state' => null,
        ])->save();
    }

    protected function resolveConversationId(): ?string
    {
        return $this->normalizedString(
            ThreadActorSession::query()
                ->where('thread_id', $this->thread->id)
                ->where('user_id', $this->actor->id)
                ->when(
                    $this->threadActor?->id,
                    fn ($query, int $threadActorId) => $query->where('thread_actor_id', $threadActorId),
                )
                ->latest('last_used_at')
                ->value('conversation_id')
        );
    }

    protected function resolveInvocationPostId(?string $parentInvocationId): ?int
    {
        $invocable = $this->resolveInvocable($parentInvocationId);

        return $invocable instanceof Post ? (int) $invocable->getKey() : null;
    }

    protected function resolveInvocable(?string $parentInvocationId): ?Model
    {
        if ($parentInvocationId !== null) {
            $parentInvocation = AgentConversationMessage::query()
                ->with('invocable')
                ->where('invocation_id', $parentInvocationId)
                ->first();

            if ($parentInvocation?->invocable instanceof Model) {
                return $parentInvocation->invocable;
            }

            $parentOutputPost = Post::query()
                ->forThread($this->thread)
                ->where('meta->source', 'agent_response')
                ->where('meta->invocation_id', $parentInvocationId)
                ->latest('id')
                ->first();
            $replyPostId = data_get($parentOutputPost?->meta, 'in_reply_to_post_id');

            if (is_numeric($replyPostId)) {
                return Post::query()->find((int) $replyPostId);
            }
        }

        return Post::query()
            ->forThread($this->thread)
            ->where('meta->source', 'agent_prompt')
            ->latest('id')
            ->first();
    }

    protected function isSubAgentAllowedForActor(string $subAgent): bool
    {
        $rules = config('ai-domain.sub_agents.allowed_by_actor', []);

        if (! is_array($rules)) {
            return true;
        }

        $actorKey = $this->threadActor?->actorName();
        $classBasedAllowed = is_string($actorKey) && is_array($rules[$actorKey] ?? null)
            ? $this->normalizeSubAgentList($rules[$actorKey])
            : [];
        $wildcardAllowed = is_array($rules['*'] ?? null)
            ? $this->normalizeSubAgentList($rules['*'])
            : [];
        $allowed = $classBasedAllowed !== [] ? $classBasedAllowed : $wildcardAllowed;

        if ($allowed === []) {
            return true;
        }

        return in_array($subAgent, $allowed, true);
    }

    protected function resolveParentInvocationId(?string $explicitParentInvocationId, ?string $memoryParentInvocationId = null): ?string
    {
        if ($explicitParentInvocationId !== null) {
            return $explicitParentInvocationId;
        }

        if ($memoryParentInvocationId !== null) {
            return $memoryParentInvocationId;
        }

        $latestAssistantInvocationId = Post::query()
            ->forThread($this->thread)
            ->where('meta->source', 'agent_response')
            ->latest('id')
            ->value('meta->invocation_id');

        return $this->normalizedString($latestAssistantInvocationId);
    }

    protected function resolveTraceId(?string $explicitTraceId, ?string $parentInvocationId, ?string $memoryTraceId = null): string
    {
        if ($explicitTraceId !== null) {
            return $explicitTraceId;
        }

        if ($memoryTraceId !== null) {
            return $memoryTraceId;
        }

        if ($parentInvocationId !== null) {
            return $parentInvocationId;
        }

        $windowSeconds = max(10, (int) config('ai-domain.sub_agents.trace_reuse_window_seconds', 120));
        $latestTraceId = ThreadEvent::query()
            ->where('thread_id', $this->thread->id)
            ->where('event_key', 'sub_agent_invoke_tool')
            ->when($this->threadActor?->id, fn ($query, int $threadActorId) => $query->where('thread_actor_id', $threadActorId))
            ->where('created_at', '>=', now()->subSeconds($windowSeconds))
            ->latest('id')
            ->value('payload->trace_id');

        return $this->normalizedString($latestTraceId) ?? (string) str()->ulid();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function enforcePolicyLimits(string $subAgent, string $traceId): ?array
    {
        $maxInvocationsPerTrace = max(1, (int) config('ai-domain.sub_agents.limits.max_invocations_per_trace', 6));
        $maxInvocationsPerSubAgentPerTrace = max(1, (int) config('ai-domain.sub_agents.limits.max_invocations_per_sub_agent_per_trace', 3));
        $stats = $this->recentInvocationStats($traceId, $subAgent);

        if (($stats['total'] ?? 0) >= $maxInvocationsPerTrace) {
            return [
                'ok' => false,
                'error_code' => 'sub_agent_trace_limit_exceeded',
                'error' => 'Sub-agent invocation limit exceeded for this trace.',
                'sub_agent' => $subAgent,
                'trace_id' => $traceId,
                'limits' => [
                    'max_invocations_per_trace' => $maxInvocationsPerTrace,
                    'max_invocations_per_sub_agent_per_trace' => $maxInvocationsPerSubAgentPerTrace,
                ],
            ];
        }

        if (($stats['per_sub_agent'] ?? 0) >= $maxInvocationsPerSubAgentPerTrace) {
            return [
                'ok' => false,
                'error_code' => 'sub_agent_per_agent_limit_exceeded',
                'error' => 'Sub-agent invocation limit exceeded for this sub-agent in current trace.',
                'sub_agent' => $subAgent,
                'trace_id' => $traceId,
                'limits' => [
                    'max_invocations_per_trace' => $maxInvocationsPerTrace,
                    'max_invocations_per_sub_agent_per_trace' => $maxInvocationsPerSubAgentPerTrace,
                ],
            ];
        }

        return null;
    }

    /**
     * @return array{total: int, per_sub_agent: int}
     */
    protected function recentInvocationStats(string $traceId, string $subAgent): array
    {
        $windowSeconds = max(10, (int) config('ai-domain.sub_agents.trace_reuse_window_seconds', 120));
        $baseQuery = ThreadEvent::query()
            ->where('thread_id', $this->thread->id)
            ->where('event_key', 'sub_agent_invoke_tool')
            ->where('created_at', '>=', now()->subSeconds($windowSeconds))
            ->when($this->threadActor?->id, fn ($query, int $threadActorId) => $query->where('thread_actor_id', $threadActorId))
            ->where('payload->trace_id', $traceId);

        return [
            'total' => (int) (clone $baseQuery)->count(),
            'per_sub_agent' => (int) (clone $baseQuery)->where('payload->sub_agent', $subAgent)->count(),
        ];
    }

    protected function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    protected function normalizeSubAgentList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $entry): ?string => $this->normalizedString($entry))
            ->filter(fn (mixed $entry): bool => is_string($entry))
            ->values()
            ->all();
    }

    protected function rememberInvocationContext(string $traceId, ?string $parentInvocationId, ?string $lastSubAgentInvocationId): void
    {
        $this->invocationMemory->write(
            thread: $this->thread,
            actor: $this->actor,
            threadActor: $this->threadActor,
            traceId: $traceId,
            parentInvocationId: $parentInvocationId,
            lastSubAgentInvocationId: $lastSubAgentInvocationId,
        );
    }

    /**
     * @return array{trace_id:?string,parent_invocation_id:?string,last_sub_agent_invocation_id:?string,updated_at:?string}
     */
    protected function readInvocationMemory(): array
    {
        return $this->invocationMemory->read($this->thread, $this->actor, $this->threadActor);
    }
}
