<?php

namespace Webong\LaravelGouge\Listeners;

use App\Events\Server\Channels\PreparingChannelOutboxPayload;
use Webong\LaravelGouge\Support\Channels\ChannelSkillContextResolver;

class AddAgentContextToChannelOutbox
{
    public function __construct(protected ChannelSkillContextResolver $channelSkillContextResolver) {}

    public function handle(PreparingChannelOutboxPayload $event): void
    {
        $skillContext = $this->channelSkillContextResolver->resolve(
            $event->channel,
            $event->route,
            $event->address,
            $event->post,
        );
        data_set($event->payload, 'delivery.skill_context', $skillContext);

        $invocationId = $this->trimmedString(data_get($event->post->meta, 'invocation_id'));

        if ($invocationId === null) {
            return;
        }

        $event->payload['event'] = 'invocation.available';
        $event->payload['invocation'] = [
            'id' => $invocationId,
            'node' => [
                'type' => 'post',
                'id' => $event->post->ulid,
            ],
            'turns_url' => route('api.form.turns.index', [
                'invocation' => $invocationId,
            ]),
        ];
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
