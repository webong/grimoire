<?php

namespace Webong\Gouge\Listeners;

use App\Events\Server\Channels\MappingChannelRoute;
use Webong\Gouge\Support\Channels\ChannelSkillContextResolver;

class AddAgentContextToChannelRoute
{
    public function __construct(protected ChannelSkillContextResolver $channelSkillContextResolver) {}

    public function handle(MappingChannelRoute $event): void
    {
        $event->payload['skills'] = $this->channelSkillContextResolver->summary(
            $event->channel,
            $event->route,
        );
    }
}
