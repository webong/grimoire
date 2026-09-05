<?php

namespace Webong\LaravelGouge\Tests\Unit;

use App\Models\Server\Channel;
use App\Support\Channels\ChannelDriverRegistry;
use App\Support\Channels\Drivers\GenericChannelDriver;
use InvalidArgumentException;
use Tests\TestCase;
use Webong\LaravelGouge\Support\AgentChannels;
use Webong\LaravelGouge\Support\Channels\Drivers\A2aChannelDriver;
use Webong\LaravelGouge\Support\Channels\Drivers\AcpChannelDriver;
use Webong\LaravelGouge\Support\Channels\Drivers\McpChannelDriver;

class ChannelDriverRegistryTest extends TestCase
{
    public function test_it_resolves_the_channel_driver_from_channel_metadata(): void
    {
        $channel = new Channel([
            'driver' => Channel::ProtocolGeneric,
        ]);

        $resolved = app(ChannelDriverRegistry::class)->resolveByChannel($channel);

        $this->assertInstanceOf(GenericChannelDriver::class, $resolved);
    }

    public function test_it_throws_for_unsupported_protocols(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported channel protocol [unsupported].');

        app(ChannelDriverRegistry::class)->resolveByProtocol('unsupported');
    }

    public function test_it_resolves_the_mcp_channel_driver(): void
    {
        $channel = new Channel([
            'driver' => AgentChannels::Mcp,
        ]);

        $resolved = app(ChannelDriverRegistry::class)->resolveByChannel($channel);

        $this->assertInstanceOf(McpChannelDriver::class, $resolved);
    }

    public function test_it_resolves_the_a2a_channel_driver(): void
    {
        $channel = new Channel([
            'driver' => AgentChannels::A2a,
        ]);

        $resolved = app(ChannelDriverRegistry::class)->resolveByChannel($channel);

        $this->assertInstanceOf(A2aChannelDriver::class, $resolved);
        $this->assertSame([AgentChannels::A2a], $resolved->supportedProtocols());
    }

    public function test_it_resolves_the_acp_channel_driver(): void
    {
        $channel = new Channel([
            'driver' => AgentChannels::Acp,
        ]);

        $resolved = app(ChannelDriverRegistry::class)->resolveByChannel($channel);

        $this->assertInstanceOf(AcpChannelDriver::class, $resolved);
        $this->assertSame([AgentChannels::Acp], $resolved->supportedProtocols());
    }

    public function test_it_maps_legacy_websocket_aliases_to_the_generic_protocol_driver(): void
    {
        $channel = new Channel([
            'driver' => Channel::TransportWebsocket,
        ]);

        $resolved = app(ChannelDriverRegistry::class)->resolveByChannel($channel);

        $this->assertInstanceOf(GenericChannelDriver::class, $resolved);
        $this->assertSame([Channel::ProtocolGeneric], $resolved->supportedProtocols());
    }
}
