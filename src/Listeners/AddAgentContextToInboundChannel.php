<?php

namespace Webong\LaravelGouge\Listeners;

use App\Events\Server\Channels\PreparingInboundChannelPayload;
use Webong\LaravelGouge\Support\Channels\ChannelSkillContextResolver;

class AddAgentContextToInboundChannel
{
    public function __construct(protected ChannelSkillContextResolver $channelSkillContextResolver) {}

    public function handle(PreparingInboundChannelPayload $event): void
    {
        $event->payload['skill_context'] = $this->channelSkillContextResolver->resolve(
            $event->channel,
            $event->route,
            $event->address,
        );
    }
}
