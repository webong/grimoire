<?php

namespace Webong\LaravelGouge\Listeners;

use App\Events\Server\Channels\MappingChannelRoute;
use Webong\LaravelGouge\Support\Channels\ChannelSkillContextResolver;

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
