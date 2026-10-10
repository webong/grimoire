<?php

namespace Webong\Gouge\Support\Channels\Drivers;

use App\Models\Server\Channel;
use App\Support\Channels\Drivers\GenericChannelDriver;
use Webong\Gouge\Support\AgentChannels;

class AcpChannelDriver extends GenericChannelDriver
{
    public function key(): string
    {
        return AgentChannels::Acp;
    }

    public function supportedTransports(): array
    {
        return [Channel::TransportHttp, Channel::TransportWebsocket, Channel::TransportStdio];
    }

    public function supportedProtocols(): array
    {
        return [AgentChannels::Acp];
    }

    public function capabilities(?Channel $channel = null): array
    {
        return ['session.invoke', 'session.delegate', 'task.read', 'post.send', 'post.receive'];
    }
}
