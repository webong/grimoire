<?php

namespace Webong\LaravelGouge\Ai\Storage\Strategies;

use App\Models\Server\Post as ThreadMessageModel;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Webong\LaravelGouge\Ai\Storage\Contracts\ThreadConversationPersistence;
use Webong\LaravelGouge\Ai\Storage\Strategies\Concerns\InteractsWithThreadActorSessions;

class ThreadContinuationPersistence implements ThreadConversationPersistence
{
    use InteractsWithThreadActorSessions;

    public function __construct(
        protected DatabaseConversationStore $store,
    ) {}

    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        return $this->latestActiveThreadUuid($participantId);
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        if ($participantType !== null && $participantId !== null) {
            $existingConversationId = $this->latestConversationId($participantType, $participantId);

            if (is_string($existingConversationId) && $existingConversationId !== '') {
                return $existingConversationId;
            }
        }

        return (string) Str::uuid7();
    }

    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt
    ): string {
        $messageId = (string) Str::uuid7();
        [$thread, $actorKey] = $this->resolveConversationContext($conversationId, $prompt->agent::class);

        if (! $thread || ! $actorKey) {
            $this->logContextMiss('storeUserMessage', $conversationId, $prompt->agent::class, $participantId);

            return $messageId;
        }

        $resolvedUserId = $this->resolveUserId($participantId);
        $storageConversationId = $this->storageConversationId($conversationId);

        $session = $this->resolveThreadActorSession($thread, $actorKey, $resolvedUserId, create: true);

        if ($session) {
            $payload = [
                'last_used_at' => now(),
            ];

            if ($resolvedUserId !== null) {
                $this->ensureAgentConversationExists(
                    $storageConversationId,
                    $participantType,
                    $resolvedUserId,
                    $conversationId,
                );
                $payload['conversation_id'] = $storageConversationId;
            }

            $session->forceFill($payload)->save();
        } else {
            $this->logContextMiss(
                'storeUserMessage.session',
                $conversationId,
                $prompt->agent::class,
                $participantId,
                $thread->uuid,
                $actorKey,
            );
        }

        return $messageId;
    }

    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response
    ): string {
        $messageId = (string) Str::uuid7();
        $storageConversationId = $this->storageConversationId($conversationId);
        $resolvedUserId = $this->resolveUserId($participantId);
        [$thread, $actorKey] = $this->resolveConversationContext($conversationId, $prompt->agent::class);

        $telemetryMeta = $this->toArrayValue($response->meta);
        $telemetryMeta['invocation_id'] = $response->invocationId;
        $telemetryMeta['conversation_id'] = $conversationId;
        $telemetryMeta['conversation_storage_id'] = $storageConversationId;
        $telemetryMeta['actor_key'] = $actorKey;
        $telemetryMeta['thread_uuid'] = $thread?->uuid;
        $telemetryMeta['thread_id'] = $thread?->id;

        if (filled($providerContentBlocks = $response->pausedProviderContentBlocks())) {
            $telemetryMeta['provider_content_blocks'] = $providerContentBlocks;
        }

        if ($resolvedUserId !== null) {
            $this->ensureAgentConversationExists(
                $storageConversationId,
                $participantType,
                $resolvedUserId,
                $conversationId,
            );

            DB::table('agent_conversation_messages')->insert([
                'id' => $messageId,
                'conversation_id' => $storageConversationId,
                'participant_type' => $participantType,
                'participant_id' => $resolvedUserId,
                'agent' => $prompt->agent::class,
                'role' => 'assistant',
                'invocation_id' => $response->invocationId,
                'trace_id' => $this->normalizedString($telemetryMeta['trace_id'] ?? null) ?? $response->invocationId,
                'parent_invocation_id' => $this->normalizedString($telemetryMeta['parent_invocation_id'] ?? null),
                'invocable_type' => null,
                'invocable_id' => null,
                'content' => trim((string) ($response->text ?? '')),
                'attachments' => '[]',
                'tool_calls' => json_encode($this->toArrayValue($response->toolCalls)),
                'tool_results' => json_encode($this->toArrayValue($response->toolResults)),
                'usage' => json_encode($this->toArrayValue($response->usage)),
                'meta' => json_encode($telemetryMeta),
                'approval_state' => $this->approvalState($response),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            Log::warning('ThreadContinuationPersistence skipped AI telemetry insert due to missing user id.', [
                'conversation_id' => $conversationId,
                'storage_conversation_id' => $storageConversationId,
                'agent' => $prompt->agent::class,
            ]);
        }

        if (! $thread || ! $actorKey) {
            $this->logContextMiss('storeAssistantMessage', $conversationId, $prompt->agent::class, $participantId);

            return $messageId;
        }

        $session = $this->resolveThreadActorSession($thread, $actorKey, $resolvedUserId, create: true);

        if ($session) {
            $payload = [
                'last_used_at' => now(),
            ];

            if ($resolvedUserId !== null) {
                $payload['conversation_id'] = $storageConversationId;
            }

            $session->forceFill($payload)->save();
        } else {
            $this->logContextMiss(
                'storeAssistantMessage.session',
                $conversationId,
                $prompt->agent::class,
                $participantId,
                $thread->uuid,
                $actorKey,
            );
        }

        return $messageId;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        [$thread] = $this->resolveConversationContext($conversationId, null);
        if ($thread) {
            return ThreadMessageModel::query()
                ->messageType()
                ->whereMorphedTo('postable', $thread)
                ->orderByDesc('id')
                ->limit(max(1, $limit))
                ->get()
                ->reverse()
                ->values()
                ->map(fn (ThreadMessageModel $message): Message => new Message(
                    $message->senderable_type === null ? 'assistant' : 'user',
                    is_string($message->text) ? $message->text : '',
                ));
        }

        Log::info('ThreadContinuationPersistence using AI conversation message fallback for context.', [
            'conversation_id' => $conversationId,
        ]);

        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->storageConversationId($conversationId))
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->reverse()
            ->values()
            ->map(fn (object $message): Message => new Message(
                (string) ($message->role ?? 'user'),
                is_string($message->content) ? $message->content : '',
            ));
    }

    /**
     * @param  array<int, ToolResult>  $toolResults
     */
    public function storeApprovalResults(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        array $toolResults
    ): void {
        $this->store->storeApprovalResults(
            $this->storageConversationId($conversationId),
            $participantType,
            $participantId,
            $toolResults,
        );
    }

    protected function approvalState(AgentResponse $response): ?string
    {
        if (! $response->hasPendingApprovals()) {
            return null;
        }

        return json_encode([
            'pending' => $response->pendingApprovals
                ->mapWithKeys(fn ($approval): array => [$approval->id => $approval->reason])
                ->all(),
        ]);
    }

    protected function toArrayValue(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->values()->all();
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof \JsonSerializable) {
            $normalized = $value->jsonSerialize();

            return is_array($normalized) ? $normalized : [];
        }

        return is_array($value) ? $value : [];
    }

    protected function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
