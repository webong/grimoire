<?php

namespace Webong\Gouge\Listeners;

use App\Events\Server\Api\PreparingResourcePayload;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use Webong\Gouge\Features\Actions\Chat\ProjectMessageExtra;
use Webong\Gouge\Features\Actions\Chat\ResolveNodeInvocation;

class AddAgentContextToResourcePayload
{
    public function __construct(
        protected ResolveNodeInvocation $resolveNodeInvocation,
        protected ProjectMessageExtra $projectMessageExtra,
    ) {}

    public function handle(PreparingResourcePayload $event): void
    {
        if (is_array($event->payload['attributes'] ?? null)) {
            $event->payload['invocation'] = $this->resolveNodeInvocation->execute(
                $event->actor,
                $event->resource,
            );

            return;
        }

        match (true) {
            $event->resource instanceof Space => $this->decorateSpace($event),
            $event->resource instanceof Thread => $this->decorateThread($event),
            $event->resource instanceof Post => $this->decoratePost($event),
            default => null,
        };
    }

    protected function decorateSpace(PreparingResourcePayload $event): void
    {
        if (is_array($event->payload['data'] ?? null)) {
            $event->payload['data']['invocation'] = $this->resolveNodeInvocation->execute(
                $event->actor,
                $event->resource,
            );
        }

        $latestMessageId = data_get($event->payload, 'space.latest_message.id');
        if (! is_string($latestMessageId) || $latestMessageId === '') {
            return;
        }

        $message = Post::query()->where('ulid', $latestMessageId)->first();
        if ($message instanceof Post) {
            data_set($event->payload, 'space.latest_message.extra', $this->projectMessageExtra->execute($message));
        }
    }

    protected function decorateThread(PreparingResourcePayload $event): void
    {
        if (is_array($event->payload['thread'] ?? null)) {
            $event->payload['thread']['invocation'] = $this->resolveNodeInvocation->execute(
                $event->actor,
                $event->resource,
            );
        }

        $messageIds = collect($event->payload['data'] ?? [])
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values();
        $messages = Post::query()
            ->whereIn('ulid', $messageIds->all())
            ->get()
            ->keyBy('ulid');

        $event->payload['data'] = collect($event->payload['data'] ?? [])
            ->map(function (mixed $payload) use ($messages): mixed {
                if (! is_array($payload)) {
                    return $payload;
                }

                $message = $messages->get($payload['id'] ?? null);
                if (! $message instanceof Post) {
                    return $payload;
                }

                $payload['is_agent'] = data_get($message->meta, 'source') === 'agent_response';
                $payload['extra'] = $this->projectMessageExtra->execute($message);

                return $payload;
            })
            ->values()
            ->all();
    }

    protected function decoratePost(PreparingResourcePayload $event): void
    {
        $event->payload['invocation'] = $this->resolveNodeInvocation->execute(
            $event->actor,
            $event->resource,
        );
    }
}
