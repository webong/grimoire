<?php

namespace Webong\LaravelGouge\Ai\Storage\Strategies\Concerns;

use App\Models\Server\SpaceActorState;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webong\LaravelGouge\Ai\Storage\ConversationId;
use Webong\LaravelGouge\Models\Server\ThreadActorSession;
use Webong\LaravelGouge\Support\AgentActors;

trait InteractsWithThreadActorSessions
{
    protected function latestActiveThreadUuid(string|int $userId): ?string
    {
        $activeThreadId = SpaceActorState::query()
            ->where('actorable_type', (new User)->getMorphClass())
            ->where('actorable_id', $userId)
            ->whereNotNull('thread_id')
            ->latest('updated_at')
            ->value('thread_id');

        if (is_int($activeThreadId) || (is_string($activeThreadId) && ctype_digit($activeThreadId))) {
            $threadUuid = Thread::query()
                ->whereKey((int) $activeThreadId)
                ->value('uuid');

            if (is_string($threadUuid) && $threadUuid !== '') {
                return $threadUuid;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?Thread, 1: ?string}
     */
    protected function resolveConversationContext(string $conversationId, ?string $agentClass): array
    {
        [$threadUuid, $encodedActorKey] = $this->splitConversationId($conversationId);

        if (! $threadUuid) {
            return [null, null];
        }

        $thread = Thread::query()
            ->where('uuid', $threadUuid)
            ->first();

        if (! $thread) {
            return [null, null];
        }

        $actorKey = $encodedActorKey
            ?: $this->actorKeyForAgentClass($agentClass)
            ?: $thread->actors()
                ->where('role', AgentActors::RolePresenter)
                ->where('status', ThreadActor::StatusActive)
                ->orderBy('priority')
                ->first()?->actorName();

        return [$thread, $actorKey];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function splitConversationId(string $conversationId): array
    {
        $trimmedConversationId = trim($conversationId);

        if ($trimmedConversationId === '') {
            return [null, null];
        }

        $parts = explode(':', $trimmedConversationId, 2);
        $threadUuid = $parts[0] ?? null;
        $actorKey = $parts[1] ?? null;

        if (! is_string($threadUuid) || $threadUuid === '') {
            return [null, null];
        }

        if (! is_string($actorKey) || $actorKey === '') {
            $actorKey = null;
        }

        return [$threadUuid, $actorKey];
    }

    protected function actorKeyForAgentClass(?string $agentClass): ?string
    {
        return null;
    }

    protected function resolveUserId(string|int|null $userId): ?int
    {
        return is_int($userId) || (is_string($userId) && ctype_digit($userId))
            ? (int) $userId
            : null;
    }

    protected function resolveThreadActorSession(
        Thread $thread,
        string $actorKey,
        ?int $userId,
        bool $create,
    ): ?ThreadActorSession {
        $threadActor = ThreadActor::query()
            ->where('thread_id', $thread->id)
            ->where('actorable_type', $actorKey)
            ->whereNull('actorable_id')
            ->where('role', AgentActors::RolePresenter)
            ->first();

        if (! $threadActor) {
            $threadActor = ThreadActor::query()
                ->where('thread_id', $thread->id)
                ->where('actorable_type', $actorKey)
                ->whereNull('actorable_id')
                ->first();
        }

        if (! $threadActor) {
            return null;
        }

        $attributes = [
            'thread_id' => $thread->id,
            'thread_actor_id' => $threadActor->id,
            'user_id' => $userId,
            'provider' => 'default',
            'model' => 'default',
        ];

        if (! $create) {
            return ThreadActorSession::query()
                ->where($attributes)
                ->first();
        }

        return ThreadActorSession::query()->firstOrCreate(
            $attributes,
            [
                'conversation_id' => null,
                'state' => null,
                'last_used_at' => null,
            ],
        );
    }

    protected function storageConversationId(string $conversationId): string
    {
        return ConversationId::toStorageId($conversationId);
    }

    protected function ensureAgentConversationExists(
        string $storageConversationId,
        ?string $participantType,
        ?int $participantId,
        string $title
    ): void {
        $exists = DB::table('agent_conversations')
            ->where('id', $storageConversationId)
            ->exists();

        if ($exists) {
            DB::table('agent_conversations')
                ->where('id', $storageConversationId)
                ->update([
                    'updated_at' => now(),
                ]);

            return;
        }

        if ($participantId === null) {
            return;
        }

        DB::table('agent_conversations')->insert([
            'id' => $storageConversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'title' => mb_substr($title, 0, 255),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function logContextMiss(
        string $operation,
        string $conversationId,
        ?string $agentClass,
        string|int|null $userId,
        ?string $threadUuid = null,
        ?string $actorKey = null,
    ): void {
        Log::warning('ThreadConversationStore could not resolve thread actor context.', [
            'operation' => $operation,
            'conversation_id' => $conversationId,
            'agent' => $agentClass,
            'user_id' => $userId,
            'thread_uuid' => $threadUuid,
            'actor_key' => $actorKey,
        ]);
    }
}
