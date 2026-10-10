<?php

namespace Webong\Gouge\Listeners;

use App\Events\Server\Channels\PreparingInboundChannelPayload;
use Webong\Gouge\Support\Channels\ChannelSkillContextResolver;

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
