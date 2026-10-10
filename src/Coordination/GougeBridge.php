<?php

namespace Webong\Gouge\Coordination;

use App\Contracts\Coordination\WorkExchange\ParticipantEndpointBridge;
use App\Contracts\Coordination\WorkExchange\WorkUpdateReceiver;
use App\Coordination\WorkExchange\V1\WorkCancellation;
use App\Coordination\WorkExchange\V1\WorkLifecycle;
use App\Coordination\WorkExchange\V1\WorkRequest;
use App\Coordination\WorkExchange\V1\WorkUpdate;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use RuntimeException;
use Throwable;
use Webong\Gouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\Gouge\Contracts\AgentRuntime;
use Webong\Gouge\Runtime\AgentRuntimeCorrelation;
use Webong\Gouge\Runtime\AgentTurn;
use Webong\Gouge\Support\AgentActors;

class GougeBridge implements ParticipantEndpointBridge
{
    public const Key = 'gouge';

    public function __construct(protected AgentRuntime $runtime) {}

    public function key(): string
    {
        return self::Key;
    }

    /**
     * @return array<string, mixed>
     */
    public function deliver(WorkRequest $request, WorkUpdateReceiver $updates): array
    {
        if ($request->action !== 'respond') {
            throw new RuntimeException("Gouge does not support action [{$request->action}].");
        }

        [$thread, $post, $recipient, $participant] = $this->resolveRequestResources($request);
        $this->rememberExchange($post, $request);
        $this->sendUpdate($request, $updates, WorkLifecycle::Accepted, 0);
        $this->sendUpdate($request, $updates, WorkLifecycle::Started, 1);

        $turn = AgentTurn::forPrompt(
            thread: $thread,
            post: $post,
            user: $recipient,
            threadActor: $participant,
            broadcastChannel: $this->stringValue(data_get($request->input, 'broadcast_space_id'))
                ?? "threads.{$thread->uuid}",
            conversationPersistenceMode: ConversationPersistenceResolver::normalizeMode(
                data_get($request->input, 'conversation_persistence'),
            ),
        );
        try {
            $this->runtime->submitTurn($turn);
        } catch (Throwable $exception) {
            $this->sendUpdate(
                request: $request,
                updates: $updates,
                state: WorkLifecycle::Failed,
                sequence: 2,
                data: ['error' => mb_substr(trim($exception->getMessage()), 0, 400)],
            );

            throw $exception;
        }

        return [
            'accepted' => true,
            'request_id' => $request->requestId,
            'correlation_id' => $request->correlationId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(WorkCancellation $cancellation, WorkUpdateReceiver $updates): array
    {
        [$thread, $post, $participant] = $this->resolveCancellationResources($cancellation);
        $actorKey = $participant->actorName() ?: AgentActors::Coordinator;
        $result = $this->runtime->cancel(new AgentRuntimeCorrelation(
            id: $cancellation->correlationId,
            runtime: 'laravel-ai',
            threadId: (int) $thread->getKey(),
            threadUuid: (string) $thread->uuid,
            postId: (int) $post->getKey(),
            postUlid: (string) $post->ulid,
            threadActorId: (int) $participant->getKey(),
            actorKey: $actorKey,
        ));
        $state = $result->accepted ? WorkLifecycle::Cancelled : WorkLifecycle::Failed;
        $updates->receive(new WorkUpdate(
            eventId: "{$cancellation->requestId}-{$state->value}",
            requestId: $cancellation->requestId,
            threadId: $cancellation->threadId,
            postId: $cancellation->postId,
            participantId: $cancellation->participantId,
            state: $state,
            sequence: 100,
            idempotencyKey: "{$cancellation->idempotencyKey}:{$state->value}",
            correlationId: $cancellation->correlationId,
            data: [
                'accepted' => $result->accepted,
                'interrupted' => $result->interrupted,
                'reason' => $result->reason,
            ],
        ), self::Key);

        return [
            'accepted' => $result->accepted,
            'interrupted' => $result->interrupted,
            'request_id' => $cancellation->requestId,
            'reason' => $result->reason,
        ];
    }

    /**
     * @return array{Thread, Post, User, ThreadActor}
     */
    protected function resolveRequestResources(WorkRequest $request): array
    {
        $thread = Thread::query()->where('uuid', $request->threadId)->first();
        $post = Post::query()->where('ulid', $request->postId)->first();
        $participant = ctype_digit($request->participantId)
            ? ThreadActor::query()->find((int) $request->participantId)
            : null;
        $recipientId = data_get($request->input, 'recipient_user_id');
        $recipient = is_numeric($recipientId) ? User::query()->find((int) $recipientId) : null;

        if (! $thread instanceof Thread || ! $post instanceof Post || ! $recipient instanceof User || ! $participant instanceof ThreadActor) {
            throw new RuntimeException('Gouge could not resolve the Figurate work request resources.');
        }

        $this->assertResourcesMatch($thread, $post, $participant);

        return [$thread, $post, $recipient, $participant];
    }

    /**
     * @return array{Thread, Post, ThreadActor}
     */
    protected function resolveCancellationResources(WorkCancellation $cancellation): array
    {
        $thread = Thread::query()->where('uuid', $cancellation->threadId)->first();
        $post = Post::query()->where('ulid', $cancellation->postId)->first();
        $participant = ctype_digit($cancellation->participantId)
            ? ThreadActor::query()->find((int) $cancellation->participantId)
            : null;

        if (! $thread instanceof Thread || ! $post instanceof Post || ! $participant instanceof ThreadActor) {
            throw new RuntimeException('Gouge could not resolve the Figurate work cancellation resources.');
        }

        $this->assertResourcesMatch($thread, $post, $participant);

        return [$thread, $post, $participant];
    }

    protected function assertResourcesMatch(Thread $thread, Post $post, ThreadActor $participant): void
    {
        if (
            $participant->thread_id !== $thread->id
            || $post->postable_type !== $thread->getMorphClass()
            || $post->postable_id !== $thread->getKey()
        ) {
            throw new RuntimeException('Gouge received mismatched Figurate work request resources.');
        }
    }

    protected function rememberExchange(Post $post, WorkRequest $request): void
    {
        $meta = is_array($post->meta) ? $post->meta : [];
        $meta['work_exchange'] = [
            'bridge' => self::Key,
            'request_id' => $request->requestId,
            'correlation_id' => $request->correlationId,
            'idempotency_key' => $request->idempotencyKey,
            'participant_id' => $request->participantId,
        ];
        $post->forceFill(['meta' => $meta])->save();
    }

    protected function sendUpdate(
        WorkRequest $request,
        WorkUpdateReceiver $updates,
        WorkLifecycle $state,
        int $sequence,
        mixed $data = null,
    ): void {
        $updates->receive(new WorkUpdate(
            eventId: "{$request->requestId}-{$state->value}",
            requestId: $request->requestId,
            threadId: $request->threadId,
            postId: $request->postId,
            participantId: $request->participantId,
            state: $state,
            sequence: $sequence,
            idempotencyKey: "{$request->idempotencyKey}:{$state->value}",
            correlationId: $request->correlationId,
            data: $data,
        ), self::Key);
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
