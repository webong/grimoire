<?php

namespace Webong\LaravelGouge\Runtime;

use App\Features\Actions\Chat\DispatchThreadMessage;
use App\Features\Actions\Chat\ThreadMessageEntry;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use RuntimeException;
use Throwable;
use Webong\LaravelGouge\Agents\LaravelAiPresenterAgent;
use Webong\LaravelGouge\Ai\Storage\ConversationId;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Ai\Support\A2a\TaskPushNotificationDispatcher;
use Webong\LaravelGouge\Contracts\AgentRuntime;
use Webong\LaravelGouge\Models\Server\AgentConversationMessage;
use Webong\LaravelGouge\Models\Server\ThreadActorSession;
use Webong\LaravelGouge\Support\AgentActors;
use Webong\LaravelGouge\Support\Orchestrate\MessageTaskService;
use Webong\LaravelGouge\Support\Orchestrate\ThreadEventTaskService;

class LaravelAiRuntime implements AgentRuntime
{
    public function __construct(
        protected DispatchThreadMessage $dispatchThreadMessage,
        protected TaskPushNotificationDispatcher $taskPushNotificationDispatcher,
        protected ThreadEventTaskService $taskService,
        protected MessageTaskService $messageTaskService,
    ) {}

    public function submitTurn(AgentTurn $turn): void
    {
        $thread = Thread::query()->find($turn->threadId);
        $post = Post::query()->find($turn->postId);
        $user = User::query()->find($turn->userId);
        $threadActor = ThreadActor::query()->find($turn->threadActorId);

        if (! $thread instanceof Thread || ! $post instanceof Post || ! $user instanceof User || ! $threadActor instanceof ThreadActor) {
            throw new RuntimeException('The Figurate resources for this agent turn could not be resolved.');
        }

        $this->queue(
            thread: $thread,
            post: $post,
            user: $user,
            threadActor: $threadActor,
            broadcastSpaceId: $turn->broadcastChannel,
            conversationPersistenceMode: $turn->conversationPersistenceMode,
            providerChannelId: $turn->providerChannelId,
        );
    }

    public function streamEvents(AgentRuntimeCorrelation $correlation): iterable
    {
        $post = Post::query()->find($correlation->postId);

        if (! $post instanceof Post) {
            return [];
        }

        $invocations = data_get($post->meta, 'invocations');
        $entry = is_array($invocations) && is_array($invocations[$correlation->actorKey] ?? null)
            ? $invocations[$correlation->actorKey]
            : null;

        if (is_array($entry)) {
            yield new AgentRuntimeEvent(
                type: 'turn.status',
                correlation: $this->correlationWithInvocation($correlation, $entry),
                payload: $entry,
                occurredAt: $post->updated_at,
            );
        }

        $thread = Thread::query()->find($correlation->threadId);

        if (! $thread instanceof Thread) {
            return;
        }

        foreach ($this->assistantRepliesForCorrelation($thread, $post, $correlation) as $reply) {
            yield new AgentRuntimeEvent(
                type: 'turn.output',
                correlation: $this->correlationWithInvocation(
                    $correlation,
                    is_array($entry) ? $entry : [],
                ),
                payload: [
                    'post_id' => $reply->id,
                    'post_ulid' => $reply->ulid,
                    'text' => $reply->text,
                    'a2ui' => data_get($reply->meta, 'a2ui'),
                ],
                occurredAt: $reply->created_at,
            );
        }
    }

    public function cancel(AgentRuntimeCorrelation $correlation): AgentRuntimeCancellation
    {
        $post = Post::query()->find($correlation->postId);

        if (! $post instanceof Post) {
            return new AgentRuntimeCancellation($correlation, false, false, 'The correlated Figurate post no longer exists.');
        }

        $meta = is_array($post->meta) ? $post->meta : [];
        $invocations = is_array($meta['invocations'] ?? null) ? $meta['invocations'] : [];
        $entry = is_array($invocations[$correlation->actorKey] ?? null) ? $invocations[$correlation->actorKey] : [];
        $status = is_string($entry['status'] ?? null) ? $entry['status'] : null;

        if (in_array($status, ['completed', 'failed', 'canceled'], true)) {
            return new AgentRuntimeCancellation(
                correlation: $this->correlationWithInvocation($correlation, $entry),
                accepted: $status === 'canceled',
                interrupted: false,
                reason: "The turn is already {$status}.",
            );
        }

        $canceledAt = now()->toIso8601String();
        $invocations[$correlation->actorKey] = [
            ...$entry,
            'status' => 'canceled',
            'invocation_id' => $entry['invocation_id'] ?? $correlation->invocationId,
            'conversation_id' => $entry['conversation_id'] ?? $correlation->conversationId,
            'recorded_at' => $canceledAt,
            'canceled_at' => $canceledAt,
        ];
        $meta['invocations'] = $invocations;

        $post->forceFill(['meta' => $meta])->save();
        $this->taskService->syncLocalTaskForPromptMessage($post);

        return new AgentRuntimeCancellation(
            correlation: $this->correlationWithInvocation($correlation, $invocations[$correlation->actorKey]),
            accepted: true,
            interrupted: false,
            reason: 'Laravel AI queue cancellation is cooperative at the Figurate persistence boundary.',
        );
    }

    public function correlationFor(AgentTurn $turn): AgentRuntimeCorrelation
    {
        $invocations = is_array($turn->metadata['invocations'] ?? null) ? $turn->metadata['invocations'] : [];
        $entry = is_array($invocations[$turn->actorKey] ?? null) ? $invocations[$turn->actorKey] : [];

        return new AgentRuntimeCorrelation(
            id: $turn->localCorrelationId(),
            runtime: 'laravel-ai',
            threadId: $turn->threadId,
            threadUuid: $turn->threadUuid,
            postId: $turn->postId,
            postUlid: $turn->postUlid,
            threadActorId: $turn->threadActorId,
            actorKey: $turn->actorKey,
            invocationId: $this->trimmedString($entry['invocation_id'] ?? null),
            conversationId: $this->trimmedString($entry['conversation_id'] ?? null),
        );
    }

    public function queue(
        Thread $thread,
        Post $post,
        User $user,
        ThreadActor $threadActor,
        string $broadcastSpaceId,
        ?string $conversationPersistenceMode = null,
        ?string $providerChannelId = null,
    ): void {
        if (
            $threadActor->thread_id !== $thread->id ||
            $post->postable_type !== $thread->getMorphClass() ||
            $post->postable_id !== $thread->getKey()
        ) {
            return;
        }

        $existingAssistantMessage = $this->findAssistantReplyForThreadActor($thread, $post, $threadActor);

        if ($existingAssistantMessage) {
            return;
        }

        if ($this->isInvocationCanceled($post, $threadActor)) {
            return;
        }

        $content = is_string($post->text) ? trim($post->text) : '';

        if ($content === '') {
            return;
        }

        $userId = $user->id;
        $session = $this->resolveThreadActorSession($thread, $threadActor, $userId);
        $handler = $this->resolveThreadActorHandler($threadActor, $user, $conversationPersistenceMode);
        if (method_exists($handler, 'setProviderChannelId')) {
            $handler->setProviderChannelId(
                $providerChannelId ?? data_get($post->meta, 'post_invocation.provider_channel_id'),
            );
        }

        $this->markPromptInvocationState(
            userPost: $post,
            threadActor: $threadActor,
            status: 'pending',
        );

        if ($session->conversation_id) {
            $handler->continue($session->conversation_id, $user);
        } else {
            $handler->forUser($user);
        }

        try {
            $queuedResponse = $handler->broadcastOnQueue(
                $content,
                [new PrivateChannel($broadcastSpaceId)],
            );

            $queuedResponse->afterCommit();
            $queuedResponse
                ->then(function (AgentResponse|StreamableAgentResponse $response) use ($thread, $post, $userId, $threadActor): void {
                    $this->handleQueuedThreadActorReplySuccess(
                        threadId: $thread->id,
                        userPostId: $post->id,
                        userId: $userId,
                        threadActorId: $threadActor->id,
                        response: $response,
                    );
                })
                ->catch(function (Throwable $exception) use ($thread, $post, $threadActor): void {
                    $this->handleQueuedThreadActorReplyFailure(
                        threadId: $thread->id,
                        userPostId: $post->id,
                        threadActorId: $threadActor->id,
                        exception: $exception,
                    );
                    report($exception);
                });
        } catch (Throwable $exception) {
            $this->markPromptInvocationState(
                userPost: $post,
                threadActor: $threadActor,
                status: 'failed',
                errorMessage: $exception->getMessage(),
            );
            report($exception);
        }
    }

    protected function resolveThreadActorSession(Thread $thread, ThreadActor $threadActor, ?int $userId): ThreadActorSession
    {
        return ThreadActorSession::query()->firstOrCreate(
            [
                'thread_id' => $thread->id,
                'thread_actor_id' => $threadActor->id,
                'user_id' => $userId,
                'provider' => 'default',
                'model' => 'default',
            ],
            [
                'conversation_id' => null,
                'state' => null,
                'last_used_at' => null,
            ],
        );
    }

    protected function resolveThreadActorHandler(ThreadActor $threadActor, User $user, ?string $requestedConversationPersistenceMode = null): Agent
    {
        $thread = $threadActor->thread;
        $conversationPersistenceMode = $this->requestedConversationPersistenceMode($requestedConversationPersistenceMode);

        $agent = match ($threadActor->actorName()) {
            default => LaravelAiPresenterAgent::make(
                thread: $thread,
                actor: $user,
            ),
        };

        if (method_exists($agent, 'setPresenterActorKey')) {
            $agent->setPresenterActorKey($threadActor->actorName());
        }

        if ($conversationPersistenceMode !== null && method_exists($agent, 'setConversationMode')) {
            $agent->setConversationMode($conversationPersistenceMode);
        }

        return $agent;
    }

    protected function requestedConversationPersistenceMode(?string $requestedMode = null): ?string
    {
        $explicitMode = ConversationPersistenceResolver::normalizeMode($requestedMode);

        if ($explicitMode !== null) {
            return $explicitMode;
        }

        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return ConversationPersistenceResolver::normalizeMode(
            $request?->input('conversation_persistence')
            ?? $request?->header('X-Conversation-Persistence')
        );
    }

    protected function findAssistantReplyForThreadActor(
        Thread $thread,
        Post $userPost,
        ThreadActor $threadActor
    ): ?Post {
        return Post::query()
            ->forThread($thread)
            ->withoutSender()
            ->where('meta->source', 'agent_response')
            ->where('meta->actor_key', $threadActor->actorName())
            ->where('meta->in_reply_to_post_id', $userPost->id)
            ->oldest('id')
            ->first();
    }

    protected function handleQueuedThreadActorReplySuccess(
        int $threadId,
        int $userPostId,
        int $userId,
        int $threadActorId,
        AgentResponse|StreamableAgentResponse $response
    ): void {
        $thread = Thread::query()->find($threadId);
        $userPost = Post::query()->find($userPostId);
        $threadActor = ThreadActor::query()->find($threadActorId);

        if (! $thread || ! $userPost || ! $threadActor) {
            return;
        }

        if (
            $threadActor->thread_id !== $thread->id ||
            $userPost->postable_type !== $thread->getMorphClass() ||
            $userPost->postable_id !== $thread->getKey()
        ) {
            return;
        }

        $existingAssistantMessage = $this->findAssistantReplyForThreadActor($thread, $userPost, $threadActor);

        if ($existingAssistantMessage) {
            return;
        }

        if ($this->isInvocationCanceled($userPost, $threadActor)) {
            return;
        }

        $session = $this->resolveThreadActorSession($thread, $threadActor, $userId);

        $this->markPromptInvocationState(
            userPost: $userPost,
            threadActor: $threadActor,
            status: 'completed',
            invocationId: $response->invocationId ?? null,
            conversationId: $response->conversationId ?? null,
        );

        if ($response->conversationId) {
            $storageConversationId = ConversationId::toStorageId($response->conversationId);

            if (! DB::table('agent_conversations')->where('id', $storageConversationId)->exists()) {
                DB::table('agent_conversations')->insert([
                    'id' => $storageConversationId,
                    'participant_type' => (new User)->getMorphClass(),
                    'participant_id' => $userId,
                    'title' => mb_substr($response->conversationId, 0, 255),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $session->forceFill([
                'conversation_id' => $storageConversationId,
                'last_used_at' => now(),
            ])->save();
        }

        $assistantText = is_string($response->text) ? trim($response->text) : '';
        [$assistantText, $assistantA2ui] = $this->extractAssistantA2uiPayload($assistantText);

        if ($assistantText === '' && ! is_array($assistantA2ui)) {
            return;
        }

        $assistantMessage = $this->dispatchThreadMessage->execute(ThreadMessageEntry::systemMessage(
            thread: $thread,
            text: $assistantText !== '' ? $assistantText : 'Interactive step ready.',
            meta: [
                'actor_key' => $threadActor->actorName(),
                'conversation_id' => $response->conversationId ?? $session->conversation_id,
                'in_reply_to_post_id' => $userPost->id,
                'invocation_id' => $response->invocationId,
                'a2ui' => is_array($assistantA2ui) ? $assistantA2ui : null,
            ],
            source: 'agent_response',
        ));

        $this->attachInvocationSourceRelation($userPost, $assistantMessage);
        $this->taskService->syncLocalTaskForPromptMessage($userPost);
        $this->linkAgentTelemetryToThreadMessages($thread, $userPost, $assistantMessage, $threadActor, $userId, $response);
    }

    protected function attachInvocationSourceRelation(Post $userPost, Post $assistantMessage): void
    {
        $sourcePostId = data_get($userPost->meta, 'post_invocation.source_post_id');

        if (! is_int($sourcePostId) && (! is_string($sourcePostId) || ! is_numeric($sourcePostId))) {
            return;
        }

        $sourcePost = Post::query()->find((int) $sourcePostId);

        if (! $sourcePost instanceof Post) {
            return;
        }

        $assistantMessage->relations()->firstOrCreate([
            'relationable_type' => $sourcePost->getMorphClass(),
            'relationable_id' => $sourcePost->getKey(),
            'role' => Post::RelationRoleDerivedFrom,
        ]);
    }

    protected function markPromptInvocationState(
        Post $userPost,
        ThreadActor $threadActor,
        string $status,
        ?string $invocationId = null,
        ?string $conversationId = null,
        ?string $errorMessage = null,
    ): void {
        $promptMeta = is_array($userPost->meta) ? $userPost->meta : [];
        $invocations = is_array($promptMeta['invocations'] ?? null) ? $promptMeta['invocations'] : [];
        $actorKey = $threadActor->actorName();

        if (! is_string($actorKey) || $actorKey === '') {
            $actorKey = AgentActors::Coordinator;
        }

        $existing = is_array($invocations[$actorKey] ?? null) ? $invocations[$actorKey] : [];
        $resolvedConversationId = is_string($conversationId) && trim($conversationId) !== ''
            ? $conversationId
            : (is_string($existing['conversation_id'] ?? null) ? $existing['conversation_id'] : null);
        $resolvedInvocationId = is_string($invocationId) && trim($invocationId) !== ''
            ? $invocationId
            : (is_string($existing['invocation_id'] ?? null) ? $existing['invocation_id'] : null);

        $invocations[$actorKey] = [
            ...$existing,
            'status' => $status,
            'invocation_id' => $resolvedInvocationId,
            'conversation_id' => $resolvedConversationId,
            'conversation_storage_id' => $resolvedConversationId
                ? ConversationId::toStorageId($resolvedConversationId)
                : null,
            'recorded_at' => now()->toIso8601String(),
        ];

        if ($status === 'failed') {
            $invocations[$actorKey]['error_message'] = is_string($errorMessage) ? mb_substr(trim($errorMessage), 0, 400) : null;
            $invocations[$actorKey]['failed_at'] = now()->toIso8601String();
        }

        $promptMeta['invocations'] = $invocations;

        $userPost->forceFill([
            'meta' => $promptMeta,
        ])->save();
        $this->taskService->syncLocalTaskForPromptMessage($userPost);

        if (is_string(data_get($promptMeta, 'a2a_task_id')) && trim((string) data_get($promptMeta, 'a2a_task_id')) !== '') {
            $this->taskPushNotificationDispatcher->dispatchTaskUpdate(
                promptPost: $userPost,
                state: $this->messageTaskService->resolveTaskState($invocations),
            );
        }
    }

    protected function linkAgentTelemetryToThreadMessages(
        Thread $thread,
        Post $userPost,
        Post $assistantMessage,
        ThreadActor $threadActor,
        int $userId,
        AgentResponse $response
    ): void {
        if (! is_string($response->conversationId) || trim($response->conversationId) === '') {
            return;
        }

        $storageConversationId = ConversationId::toStorageId($response->conversationId);
        $rows = AgentConversationMessage::query()
            ->where('conversation_id', $storageConversationId)
            ->where('participant_id', $userId)
            ->where('agent', LaravelAiPresenterAgent::class)
            ->where('role', 'assistant')
            ->where(function ($query) use ($response): void {
                $query->where('invocation_id', $response->invocationId)
                    ->orWhere(function ($fallbackQuery) use ($response): void {
                        $fallbackQuery
                            ->whereNull('invocation_id')
                            ->where('meta->invocation_id', $response->invocationId);
                    });
            })
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $telemetry = $rows->first(function (AgentConversationMessage $message) use ($response): bool {
            if ($message->invocation_id === $response->invocationId) {
                return true;
            }

            $meta = json_decode((string) $message->meta, true);

            return is_array($meta) && ($meta['invocation_id'] ?? null) === $response->invocationId;
        });

        if (! $telemetry instanceof AgentConversationMessage) {
            return;
        }

        $meta = json_decode((string) $telemetry->meta, true);
        $meta = is_array($meta) ? $meta : [];
        $actorKey = $threadActor->actorName();

        if (! is_string($actorKey) || $actorKey === '') {
            $actorKey = AgentActors::Coordinator;
        }

        $meta['thread_id'] = $thread->id;
        $meta['thread_uuid'] = $thread->uuid;
        $meta['thread_post_id'] = $assistantMessage->id;
        $meta['in_reply_to_post_id'] = $userPost->id;
        $meta['actor_key'] = $actorKey;

        $telemetry->forceFill([
            'invocation_id' => $response->invocationId,
            'trace_id' => $telemetry->trace_id ?: $response->invocationId,
            'invocable_type' => $userPost->getMorphClass(),
            'invocable_id' => $userPost->getKey(),
            'meta' => json_encode($meta),
        ])->save();

        AgentConversationMessage::query()
            ->where('conversation_id', $storageConversationId)
            ->where('participant_id', $userId)
            ->whereMorphedTo('invocable', $userPost)
            ->whereNull('parent_invocation_id')
            ->where('meta->kind', 'sub_agent')
            ->where('id', '!=', $telemetry->id)
            ->update([
                'trace_id' => $response->invocationId,
                'parent_invocation_id' => $response->invocationId,
                'updated_at' => now(),
            ]);
    }

    protected function handleQueuedThreadActorReplyFailure(
        int $threadId,
        int $userPostId,
        int $threadActorId,
        Throwable $exception
    ): void {
        $thread = Thread::query()->find($threadId);
        $userPost = Post::query()->find($userPostId);
        $threadActor = ThreadActor::query()->find($threadActorId);

        if (! $thread || ! $userPost || ! $threadActor) {
            return;
        }

        if (
            $threadActor->thread_id !== $thread->id ||
            $userPost->postable_type !== $thread->getMorphClass() ||
            $userPost->postable_id !== $thread->getKey()
        ) {
            return;
        }

        if ($this->isInvocationCanceled($userPost, $threadActor)) {
            return;
        }

        $this->markPromptInvocationState(
            userPost: $userPost,
            threadActor: $threadActor,
            status: 'failed',
            errorMessage: $exception->getMessage(),
        );
    }

    protected function isInvocationCanceled(Post $userPost, ThreadActor $threadActor): bool
    {
        $actorKey = $threadActor->actorName();

        if (! is_string($actorKey) || $actorKey === '') {
            $actorKey = AgentActors::Coordinator;
        }

        $status = data_get($userPost->meta, "invocations.{$actorKey}.status");

        return is_string($status) && $status === 'canceled';
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    protected function extractAssistantA2uiPayload(string $assistantText): array
    {
        $decoded = $this->decodePossibleJsonObject($assistantText);

        if (! is_array($decoded)) {
            return [$assistantText, null];
        }

        $payload = is_array($decoded['a2ui'] ?? null) ? $decoded['a2ui'] : null;

        if (! is_array($payload) && $this->looksLikeA2uiPayload($decoded)) {
            $payload = $decoded;
        }

        if (! is_array($payload)) {
            return [$assistantText, null];
        }

        $resolvedText = $this->resolvedAssistantTextFromDecodedPayload($decoded, $assistantText);
        $payload = $this->normalizeAssistantA2uiPayload($payload);

        if (! is_array($payload)) {
            return [$assistantText, null];
        }

        return [$resolvedText, $payload];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodePossibleJsonObject(string $assistantText): ?array
    {
        $trimmed = trim($assistantText);

        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (! preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $trimmed, $matches)) {
            return null;
        }

        $fencedDecoded = json_decode((string) ($matches[1] ?? ''), true);

        return is_array($fencedDecoded) ? $fencedDecoded : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    protected function looksLikeA2uiPayload(array $decoded): bool
    {
        return array_key_exists('surfaceUpdate', $decoded)
            || array_key_exists('dataModelUpdate', $decoded)
            || array_key_exists('beginRendering', $decoded)
            || array_key_exists('deleteSurface', $decoded);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    protected function resolvedAssistantTextFromDecodedPayload(array $decoded, string $assistantText): string
    {
        $candidates = [
            $decoded['text'] ?? null,
            $decoded['message'] ?? null,
            data_get($decoded, 'a2ui.text'),
            data_get($decoded, 'a2ui.message'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $this->looksLikeA2uiPayload($decoded) || is_array($decoded['a2ui'] ?? null)
            ? ''
            : $assistantText;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function normalizeAssistantA2uiPayload(array $payload): ?array
    {
        $normalized = $payload;
        $hasRecognizedKey = false;

        foreach (['beginRendering', 'surfaceUpdate'] as $key) {
            if (! is_array($normalized[$key] ?? null)) {
                continue;
            }

            $surface = $this->normalizeAssistantA2uiSurface($normalized[$key]);

            if ($surface === null) {
                unset($normalized[$key]);

                continue;
            }

            $normalized[$key] = $surface;
            $hasRecognizedKey = true;
        }

        if (is_array($normalized['dataModelUpdate'] ?? null) || is_string($normalized['dataModelUpdate'] ?? null)) {
            $hasRecognizedKey = true;
        }

        if (is_string($normalized['deleteSurface'] ?? null) && trim($normalized['deleteSurface']) !== '') {
            $normalized['deleteSurface'] = trim($normalized['deleteSurface']);
            $hasRecognizedKey = true;
        }

        if (! $hasRecognizedKey && $this->looksLikeAssistantSurface($normalized)) {
            $surface = $this->normalizeAssistantA2uiSurface($normalized);

            if ($surface === null) {
                return null;
            }

            return ['beginRendering' => $surface];
        }

        return $hasRecognizedKey ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $surface
     * @return array<string, mixed>|null
     */
    protected function normalizeAssistantA2uiSurface(array $surface): ?array
    {
        $surfaceId = is_string($surface['id'] ?? null) ? trim($surface['id']) : '';

        if ($surfaceId === '') {
            $surfaceId = is_string($surface['surfaceId'] ?? null) ? trim($surface['surfaceId']) : '';
        }

        if ($surfaceId === '') {
            $surfaceId = 'surface_'.substr(sha1(json_encode($surface)), 0, 12);
        }

        $fields = is_array($surface['fields'] ?? null) ? $surface['fields'] : [];
        $actions = is_array($surface['actions'] ?? null) ? $surface['actions'] : [];

        $normalizedActions = collect($actions)
            ->map(fn (mixed $action, int $index): ?array => is_array($action) ? $this->normalizeAssistantA2uiAction($action, $index, $surfaceId) : null)
            ->filter(fn (mixed $action): bool => is_array($action))
            ->values()
            ->all();

        if ($normalizedActions === [] && $fields !== []) {
            $normalizedActions = [[
                'id' => "{$surfaceId}_submit",
                'name' => 'submit',
                'type' => 'submit',
                'label' => 'Submit',
            ]];
        }

        $normalized = $surface;
        $normalized['id'] = $surfaceId;
        $normalized['actions'] = $normalizedActions;

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>|null
     */
    protected function normalizeAssistantA2uiAction(array $action, int $index, string $surfaceId): ?array
    {
        $name = is_string($action['name'] ?? null) && trim($action['name']) !== ''
            ? trim($action['name'])
            : null;
        $type = is_string($action['type'] ?? null) && trim($action['type']) !== ''
            ? trim($action['type'])
            : null;
        $id = is_string($action['id'] ?? null) && trim($action['id']) !== ''
            ? trim($action['id'])
            : null;

        if ($id === null) {
            $id = "{$surfaceId}_action_".($index + 1);
        }

        if ($name === null && $type === null) {
            $name = 'submit';
            $type = 'submit';
        }

        $sourceComponentId = is_string($action['sourceComponentId'] ?? null) && trim($action['sourceComponentId']) !== ''
            ? trim($action['sourceComponentId'])
            : null;

        return array_filter([
            ...$action,
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'sourceComponentId' => $sourceComponentId,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function looksLikeAssistantSurface(array $payload): bool
    {
        return is_array($payload['fields'] ?? null)
            || is_array($payload['actions'] ?? null)
            || is_array(data_get($payload, 'schema.fields'))
            || (is_string($payload['title'] ?? null) && trim($payload['title']) !== '')
            || (is_string($payload['question'] ?? null) && trim($payload['question']) !== '');
    }

    /**
     * @return Collection<int, Post>
     */
    protected function assistantRepliesForCorrelation(
        Thread $thread,
        Post $post,
        AgentRuntimeCorrelation $correlation,
    ): Collection {
        return Post::query()
            ->forThread($thread)
            ->withoutSender()
            ->where('meta->source', 'agent_response')
            ->where('meta->actor_key', $correlation->actorKey)
            ->where('meta->in_reply_to_post_id', $post->id)
            ->oldest('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function correlationWithInvocation(AgentRuntimeCorrelation $correlation, array $entry): AgentRuntimeCorrelation
    {
        return new AgentRuntimeCorrelation(
            id: $correlation->id,
            runtime: $correlation->runtime,
            threadId: $correlation->threadId,
            threadUuid: $correlation->threadUuid,
            postId: $correlation->postId,
            postUlid: $correlation->postUlid,
            threadActorId: $correlation->threadActorId,
            actorKey: $correlation->actorKey,
            invocationId: $this->trimmedString($entry['invocation_id'] ?? null) ?? $correlation->invocationId,
            conversationId: $this->trimmedString($entry['conversation_id'] ?? null) ?? $correlation->conversationId,
        );
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
