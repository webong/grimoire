<?php

namespace Webong\Gouge\Support\Orchestrate;

use App\Features\Actions\Chat\ResolveConversationThreadContext;
use App\Models\Server\Channel;
use App\Models\Server\Post;
use App\Models\Server\PostRelation;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\User;
use App\Support\Channels\ChannelAccess;
use App\Support\Channels\ChannelApiResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use JsonException;
use Webong\Gouge\Features\Operations\Chat\DispatchPromptOperation;
use Webong\Gouge\Support\AgentActors;
use Webong\Gouge\Support\AgentChannels;

class PostInvocationService
{
    protected const SourceEnvelopeMaxBytes = 65536;

    public function __construct(
        protected DispatchPromptOperation $dispatchPromptOperation,
        protected MessageTaskService $messageTaskService,
        protected ResolveConversationThreadContext $resolveConversationThreadContext,
        protected ThreadEventTaskService $taskService,
        protected ChannelAccess $channelAccess,
        protected ChannelApiResolver $channelApiResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function invoke(User $actor, Post $sourcePost, string $instructions, ?string $providerChannelId = null): array
    {
        [$space, $thread] = $this->resolveExecutionContext($actor, $sourcePost);
        $providerChannel = $this->resolveProviderChannel(
            actor: $actor,
            sourcePost: $sourcePost,
            space: $space,
            thread: $thread,
            identifier: $providerChannelId,
        );
        $sourceEnvelope = $this->validatedSourceEnvelope($sourcePost, $space, $thread);
        $promptText = $this->promptText($instructions, $sourceEnvelope);

        return DB::transaction(function () use ($actor, $instructions, $promptText, $sourcePost, $space, $thread, $providerChannel): array {
            $dispatch = $this->dispatchPromptOperation->run(
                space: $space,
                thread: $thread,
                actor: $actor,
                text: $promptText,
                options: [
                    'agent_source' => 'agent_prompt',
                    'direct_source' => 'agent_prompt',
                    'dispatch_observers_when_direct' => false,
                    'dispatch_observers_when_agent' => false,
                    'ensure_membership' => true,
                    'ensure_presenter' => true,
                    'presenter_actor_type' => AgentActors::Coordinator,
                    'broadcast_space_id' => "threads.{$thread->uuid}",
                    'meta' => [
                        'post_invocation' => [
                            'source_post_id' => $sourcePost->getKey(),
                            'source_post_ulid' => $sourcePost->ulid,
                            'instructions' => $instructions,
                            'provider_channel_id' => $providerChannel?->uuid,
                            'requested_at' => now()->toIso8601String(),
                        ],
                    ],
                ],
            );

            /** @var Post $promptMessage */
            $promptMessage = $dispatch['message'];
            $promptMessage->relations()->firstOrCreate([
                'relationable_type' => $sourcePost->getMorphClass(),
                'relationable_id' => $sourcePost->getKey(),
                'role' => Post::RelationRoleDerivedFrom,
            ]);

            $task = $this->taskService->createLocalTask(
                promptMessage: $promptMessage,
                user: $actor,
                payload: [
                    'local' => [
                        'protocol' => 'post_invocation',
                        'owner' => [
                            'subject_type' => $actor->getMorphClass(),
                            'subject_id' => $actor->getKey(),
                        ],
                    ],
                    'post_invocation' => [
                        'source_post_id' => $sourcePost->getKey(),
                        'source_post_ulid' => $sourcePost->ulid,
                        'provider_channel_id' => $providerChannel?->uuid,
                    ],
                ],
                stateOverride: 'submitted',
            );

            return $this->taskPayload($task, $sourcePost);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function task(User $actor, string $taskId): array
    {
        $task = $this->taskService->resolveOwnedPostInvocationTask($actor, $taskId);
        abort_unless($task instanceof TaskRecord, 404);

        $task = $this->taskService->syncLocalTask($task);
        $sourcePost = $this->resolveTaskSourcePost($task);

        if ($sourcePost instanceof Post) {
            Gate::forUser($actor)->authorize('view', $sourcePost);
        }

        return $this->taskPayload($task, $sourcePost);
    }

    /**
     * @return array{Space, Thread}
     */
    protected function resolveExecutionContext(User $actor, Post $sourcePost): array
    {
        $postable = $sourcePost->postable;

        if ($postable instanceof Thread) {
            Gate::forUser($actor)->authorize('view', $postable);

            [$space] = $this->resolveConversationThreadContext->execute($postable->uuid);
            Gate::forUser($actor)->authorize('view', $space);

            return [$space, $postable];
        }

        if ($postable instanceof Space) {
            Gate::forUser($actor)->authorize('view', $postable);

            return [$postable, $this->createReviewThread($actor, $postable, $sourcePost)];
        }

        abort(422, 'The source post must belong directly to a space or thread.');
    }

    protected function resolveProviderChannel(
        User $actor,
        Post $sourcePost,
        Space $space,
        Thread $thread,
        ?string $identifier,
    ): ?Channel {
        if (! is_string($identifier) || trim($identifier) === '') {
            return null;
        }

        $channel = $this->channelApiResolver->channel(trim($identifier));

        abort_unless(
            $channel->protocolKey() === AgentChannels::AiProvider,
            422,
            'The selected channel is not an AI provider channel.',
        );
        abort_unless(
            $channel->enabled && $channel->status === Channel::StatusActive,
            422,
            'The selected AI provider channel is not active.',
        );

        foreach ([$sourcePost, $thread, $space] as $owner) {
            if ($this->channelAccess->isAttachedTo($actor, $channel, $owner)) {
                return $channel;
            }
        }

        abort(403, 'The selected AI provider channel is not attached to the source context.');
    }

    protected function createReviewThread(User $actor, Space $space, Post $sourcePost): Thread
    {
        Gate::forUser($actor)->authorize('create', Thread::class);

        return $space->threads()->create([
            'title' => sprintf('Post review %s', mb_substr($sourcePost->ulid, 0, 10)),
            'purpose' => 'post_review',
            'phase' => 'review_requested',
            'status' => 'open',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedSourceEnvelope(Post $sourcePost, Space $space, Thread $thread): array
    {
        $envelope = [
            'id' => $sourcePost->ulid,
            'type' => $sourcePost->type,
            'tag' => $sourcePost->tag,
            'status' => $sourcePost->status,
            'space_id' => $space->uuid,
            'thread_id' => $thread->uuid,
            'postable' => $this->postableReference($sourcePost->postable),
            'occurred_at' => optional($sourcePost->occurred_at)?->toIso8601String(),
            'text' => $sourcePost->text,
            'payload' => $sourcePost->payload ?? [],
        ];

        $encoded = $this->encodeSourceEnvelope($envelope);
        abort_if(
            strlen($encoded) > self::SourceEnvelopeMaxBytes,
            422,
            'The source post envelope may not exceed 64 KiB.',
        );

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    protected function encodeSourceEnvelope(array $envelope): string
    {
        try {
            return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422, 'The source post envelope could not be encoded.');
        }
    }

    /**
     * @param  array<string, mixed>  $sourceEnvelope
     */
    protected function promptText(string $instructions, array $sourceEnvelope): string
    {
        return implode("\n\n", [
            'Review the Figurate source post using these instructions.',
            "Instructions:\n".$instructions,
            "Source post JSON:\n".$this->encodeSourceEnvelope($sourceEnvelope),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function taskPayload(TaskRecord $task, ?Post $sourcePost = null): array
    {
        $promptMessage = $task->message;
        abort_unless($promptMessage instanceof Post, 404);

        $snapshot = $this->messageTaskService->snapshot($promptMessage);
        $thread = $snapshot['thread'];
        $space = $snapshot['space'];
        $sourcePost ??= $this->resolveTaskSourcePost($task);

        return [
            'id' => $this->taskService->publicId($task),
            'kind' => 'task',
            'state' => $task->status,
            'source_post' => $sourcePost instanceof Post ? $this->sourcePostPayload($sourcePost) : null,
            'space_id' => $space?->uuid ?? $task->spaceId,
            'thread_id' => $thread?->uuid,
            'prompt' => [
                'id' => $promptMessage->ulid,
                'text' => $this->promptInstructions($promptMessage),
                'created_at' => optional($promptMessage->created_at)?->toIso8601String(),
            ],
            'invocations' => $this->messageTaskService->invocationPayload($snapshot['invocations']),
            'artifacts' => $snapshot['assistant_replies']
                ->map(fn (Post $message): array => $this->artifactPayload($message))
                ->values()
                ->all(),
        ];
    }

    protected function resolveTaskSourcePost(TaskRecord $task): ?Post
    {
        $sourcePostId = data_get($task->lastPayload, 'post_invocation.source_post_id')
            ?? data_get($task->message?->meta, 'post_invocation.source_post_id');

        if (is_int($sourcePostId) || (is_string($sourcePostId) && is_numeric($sourcePostId))) {
            return Post::query()->find((int) $sourcePostId);
        }

        $sourcePostUlid = data_get($task->lastPayload, 'post_invocation.source_post_ulid')
            ?? data_get($task->message?->meta, 'post_invocation.source_post_ulid');

        if (is_string($sourcePostUlid) && trim($sourcePostUlid) !== '') {
            return Post::query()->where('ulid', trim($sourcePostUlid))->first();
        }

        return null;
    }

    protected function promptInstructions(Post $promptMessage): string
    {
        $instructions = data_get($promptMessage->meta, 'post_invocation.instructions');

        if (is_string($instructions) && trim($instructions) !== '') {
            return trim($instructions);
        }

        return is_string($promptMessage->text) ? $promptMessage->text : '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function sourcePostPayload(Post $sourcePost): array
    {
        return [
            'id' => $sourcePost->ulid,
            'type' => $sourcePost->type,
            'tag' => $sourcePost->tag,
            'status' => $sourcePost->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function artifactPayload(Post $message): array
    {
        return [
            'id' => $message->ulid,
            'role' => 'assistant',
            'text' => is_string($message->text) ? $message->text : '',
            'actor_key' => data_get($message->meta, 'actor_key'),
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'a2ui' => is_array(data_get($message->meta, 'a2ui')) ? data_get($message->meta, 'a2ui') : null,
            'source_relations' => $message->relations()
                ->where('role', Post::RelationRoleDerivedFrom)
                ->get()
                ->map(fn (PostRelation $relation): ?array => $this->relationPayload($relation))
                ->filter(fn (mixed $relation): bool => is_array($relation))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function relationPayload(PostRelation $relation): ?array
    {
        $target = $relation->relationable;

        if (! $target instanceof Space && ! $target instanceof Thread && ! $target instanceof Post) {
            return null;
        }

        return [
            'role' => $relation->role,
            'target' => $this->nodeReference($target),
        ];
    }

    /**
     * @return array{type: 'space'|'thread'|'post', id: string}|null
     */
    protected function postableReference(mixed $postable): ?array
    {
        if (! $postable instanceof Space && ! $postable instanceof Thread && ! $postable instanceof Post) {
            return null;
        }

        return $this->nodeReference($postable);
    }

    /**
     * @return array{type: 'space'|'thread'|'post', id: string}
     */
    protected function nodeReference(Space|Thread|Post $node): array
    {
        return [
            'type' => match (true) {
                $node instanceof Space => 'space',
                $node instanceof Thread => 'thread',
                $node instanceof Post => 'post',
                default => abort(422, 'Unsupported node reference.'),
            },
            'id' => $node instanceof Post ? $node->ulid : $node->uuid,
        ];
    }
}
