<?php

namespace Webong\Gouge\Tests\Unit;

use App\Models\Server\Channel;
use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Webong\Gouge\Ai\Support\Mcp\McpRegistry;
use Webong\Gouge\Support\AgentChannels;

class McpRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_link_runtime_fields_for_context_servers(): void
    {
        $user = User::factory()->create();
        $space = Space::factory()->create();
        SpaceActorState::query()->create([
            'space_id' => $space->id,
            'thread_id' => null,
            'actorable_type' => $user->getMorphClass(),
            'actorable_id' => $user->id,
            'status' => SpaceActorState::StatusActive,
        ]);
        $channel = Channel::factory()->create([
            'driver' => AgentChannels::Mcp,
            'server' => 'filesystem',
            'transport' => 'websocket',
            'endpoint_url' => 'wss://agents.example/mcp',
            'allowed_tools' => ['search'],
        ]);

        app(ChannelLinkRepository::class)->create($channel, $space, $space, [
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionBidirectional,
            'config' => [
                'transport' => 'websocket',
                'mode' => 'remote',
                'endpoint_url' => 'wss://override.example/mcp',
                'default_timeout_ms' => 12000,
            ],
            'data' => [],
            'meta' => [],
        ]);

        $resolved = app(McpRegistry::class)->resolve('filesystem', null, $user);

        $this->assertSame('websocket', $resolved['transport']);
        $this->assertSame('remote', $resolved['mode']);
        $this->assertSame('wss://override.example/mcp', $resolved['endpoint_url']);
        $this->assertSame(12000, data_get($resolved, 'config.default_timeout_ms'));
        $this->assertSame(['search'], $resolved['tools']);
        $this->assertSame('User', $resolved['context_source']);
    }

    public function test_it_does_not_expose_config_only_servers_without_registered_channels(): void
    {
        config()->set('services.mcp.servers.filesystem', [
            'transport' => 'http',
            'endpoint_url' => 'https://config.example/mcp',
            'tools' => ['search'],
        ]);

        $resolver = app(McpRegistry::class);

        $this->assertFalse($resolver->enabled());
        $this->assertSame([], $resolver->available());

        $resolved = $resolver->resolve('filesystem');

        $this->assertFalse($resolved['enabled']);
        $this->assertSame([], $resolved['tools']);
        $this->assertNull($resolved['endpoint_url']);
    }
}
