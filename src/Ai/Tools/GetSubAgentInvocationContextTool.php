<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Webong\LaravelGouge\Ai\Support\SubAgents\SubAgentInvocationMemory;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;

class GetSubAgentInvocationContextTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
        protected ?ThreadActor $threadActor = null,
        protected SubAgentInvocationMemory $invocationMemory = new SubAgentInvocationMemory,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Read or refresh session-scoped sub-agent invocation context (trace_id and parent_invocation_id).';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $remember = (bool) ($request['remember'] ?? false);
        $incomingTraceId = $this->normalizedString($request['trace_id'] ?? null);
        $incomingParentInvocationId = $this->normalizedString($request['parent_invocation_id'] ?? null);

        $memory = $this->invocationMemory->read($this->thread, $this->actor, $this->threadActor);
        $inferredParentInvocationId = $this->inferParentInvocationId();
        $suggestedParentInvocationId = $incomingParentInvocationId
            ?? $memory['parent_invocation_id']
            ?? $inferredParentInvocationId;
        $suggestedTraceId = $incomingTraceId
            ?? $memory['trace_id']
            ?? $suggestedParentInvocationId
            ?? $this->inferRecentTraceId()
            ?? (string) str()->ulid();

        $remembered = false;

        if ($remember) {
            $remembered = $this->invocationMemory->write(
                thread: $this->thread,
                actor: $this->actor,
                threadActor: $this->threadActor,
                traceId: $suggestedTraceId,
                parentInvocationId: $suggestedParentInvocationId,
                lastSubAgentInvocationId: $memory['last_sub_agent_invocation_id'],
            );

            if ($remembered) {
                $memory = $this->invocationMemory->read($this->thread, $this->actor, $this->threadActor);
            }
        }

        return $this->ok([
            'memory' => $memory,
            'inferred_parent_invocation_id' => $inferredParentInvocationId,
            'suggested' => [
                'trace_id' => $suggestedTraceId,
                'parent_invocation_id' => $suggestedParentInvocationId,
            ],
            'remembered' => $remembered,
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'remember' => $schema->boolean(),
            'trace_id' => $schema->string(),
            'parent_invocation_id' => $schema->string(),
        ];
    }

    protected function inferParentInvocationId(): ?string
    {
        $latestAssistantInvocationId = Post::query()
            ->forThread($this->thread)
            ->where('meta->source', 'agent_response')
            ->latest('id')
            ->value('meta->invocation_id');

        return $this->normalizedString($latestAssistantInvocationId);
    }

    protected function inferRecentTraceId(): ?string
    {
        $windowSeconds = max(10, (int) config('ai-domain.sub_agents.trace_reuse_window_seconds', 120));
        $latestTraceId = ThreadEvent::query()
            ->where('thread_id', $this->thread->id)
            ->where('event_key', 'sub_agent_invoke_tool')
            ->when($this->threadActor?->id, fn ($query, int $threadActorId) => $query->where('thread_actor_id', $threadActorId))
            ->where('created_at', '>=', now()->subSeconds($windowSeconds))
            ->latest('id')
            ->value('payload->trace_id');

        return $this->normalizedString($latestTraceId);
    }

    protected function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
