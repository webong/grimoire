<?php

namespace Webong\Gouge\Ai\Support\SubAgents;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Webong\Gouge\Models\Server\ThreadActorSession;

class SubAgentInvocationMemory
{
    /**
     * @return array{trace_id:?string,parent_invocation_id:?string,last_sub_agent_invocation_id:?string,updated_at:?string}
     */
    public function read(Thread $thread, User $actor, ?ThreadActor $threadActor = null): array
    {
        $session = $this->resolveSession($thread, $actor, $threadActor);

        if (! $session instanceof ThreadActorSession) {
            return [
                'trace_id' => null,
                'parent_invocation_id' => null,
                'last_sub_agent_invocation_id' => null,
                'updated_at' => null,
            ];
        }

        $state = is_array($session->state) ? $session->state : [];
        $context = is_array($state['sub_agent_invocation'] ?? null) ? $state['sub_agent_invocation'] : [];

        return [
            'trace_id' => $this->normalizedString($context['trace_id'] ?? null),
            'parent_invocation_id' => $this->normalizedString($context['parent_invocation_id'] ?? null),
            'last_sub_agent_invocation_id' => $this->normalizedString($context['last_sub_agent_invocation_id'] ?? null),
            'updated_at' => $this->normalizedString($context['updated_at'] ?? null),
        ];
    }

    public function write(
        Thread $thread,
        User $actor,
        ?ThreadActor $threadActor,
        string $traceId,
        ?string $parentInvocationId,
        ?string $lastSubAgentInvocationId = null,
    ): bool {
        $session = $this->resolveSession($thread, $actor, $threadActor);

        if (! $session instanceof ThreadActorSession) {
            return false;
        }

        $state = is_array($session->state) ? $session->state : [];
        $state['sub_agent_invocation'] = [
            'trace_id' => $this->normalizedString($traceId),
            'parent_invocation_id' => $this->normalizedString($parentInvocationId),
            'last_sub_agent_invocation_id' => $this->normalizedString($lastSubAgentInvocationId),
            'updated_at' => now()->toIso8601String(),
        ];

        $session->forceFill([
            'state' => $state,
            'last_used_at' => now(),
        ])->save();

        return true;
    }

    protected function resolveSession(Thread $thread, User $actor, ?ThreadActor $threadActor = null): ?ThreadActorSession
    {
        return ThreadActorSession::query()
            ->where('thread_id', $thread->id)
            ->where('user_id', $actor->id)
            ->when($threadActor?->id, fn ($query, int $threadActorId) => $query->where('thread_actor_id', $threadActorId))
            ->latest('updated_at')
            ->first();
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
