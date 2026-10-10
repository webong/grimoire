<?php

namespace Webong\Gouge\Support\Channels\Drivers;

use App\Models\Server\Channel;
use App\Support\Channels\Drivers\GenericChannelDriver;
use Webong\Gouge\Support\AgentChannels;

class A2aChannelDriver extends GenericChannelDriver
{
    public function key(): string
    {
        return AgentChannels::A2a;
    }

    public function supportedTransports(): array
    {
        return [Channel::TransportHttp, Channel::TransportWebsocket, Channel::TransportStdio];
    }

    public function supportedProtocols(): array
    {
        return [AgentChannels::A2a];
    }

    public function capabilities(?Channel $channel = null): array
    {
        return ['agent.invoke', 'task.delegate', 'task.read', 'post.send', 'post.receive'];
    }
}
