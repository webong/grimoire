<?php

namespace Webong\Gouge\Tests\Feature\Mcp;

use App\Models\Server\Channel;
use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use App\TokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Webong\Gouge\Support\AgentChannels;

class InboundServerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_webhook_channel_via_the_generic_channel_api(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $response = $this->postJson(route('api.channels.store'), [
            'owner_type' => 'user',
            'owner_id' => 'me',
            'protocol' => Channel::ProtocolGeneric,
            'name' => 'inbound-webhook',
            'label' => 'Inbound Webhook',
            'transport' => Channel::TransportWebhook,
            'endpoint_url' => 'https://hooks.example/inbound',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.protocol', Channel::ProtocolGeneric)
            ->assertJsonPath('data.transport', Channel::TransportWebhook)
            ->assertJsonPath('data.name', 'inbound-webhook')
            ->assertJsonPath('owner.type', 'user')
            ->assertJsonPath('data.space.id', fn (mixed $value): bool => is_string($value) && $value !== '');

        $this->assertDatabaseHas('channels', [
            'driver' => Channel::ProtocolGeneric,
            'server' => 'inbound-webhook',
            'transport' => Channel::TransportWebhook,
        ]);
    }

    public function test_it_rejects_untrusted_remote_context_server_urls_on_create(): void
    {
        config()->set('services.mcp.trust', [
            'allow_http' => false,
            'allow_private_network' => false,
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $response = $this->postJson(route('api.channels.store'), [
            'context_type' => 'user',
            'context_id' => 'me',
            'protocol' => AgentChannels::Mcp,
            'server' => 'planner',
            'transport' => Channel::TransportHttp,
            'config' => [
                'mode' => 'remote',
            ],
            'endpoint_url' => 'http://127.0.0.1:3000/mcp',
            'allowed_tools' => ['search'],
        ]);

        $response->assertStatus(422)
            ->assertInvalid([
                'endpoint_url' => 'Plain HTTP URLs are not allowed by policy.',
            ]);
    }

    public function test_it_rejects_untrusted_remote_context_server_urls_on_update(): void
    {
        config()->set('services.mcp.trust', [
            'allow_http' => false,
            'allow_private_network' => false,
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $contextServer = Channel::query()->create([
            'name' => 'Planner',
            'driver' => AgentChannels::Mcp,
            'server' => 'planner',
            'label' => 'Planner',
            'enabled' => true,
            'priority' => 0,
            'transport' => Channel::TransportHttp,
            'endpoint_url' => 'https://agents.example/mcp',
            'allowed_tools' => ['search'],
        ]);
        $space = Space::factory()->create();
        SpaceActorState::query()->create([
            'space_id' => $space->id,
            'thread_id' => null,
            'actorable_type' => $user->getMorphClass(),
            'actorable_id' => $user->id,
            'status' => SpaceActorState::StatusActive,
        ]);
        app(ChannelLinkRepository::class)->create($contextServer, $space, $space);

        $response = $this->patchJson(route('api.channels.update', ['channel' => $contextServer->id]), [
            'endpoint_url' => 'http://127.0.0.1:3000/mcp',
        ]);

        $response->assertStatus(422)
            ->assertInvalid([
                'endpoint_url' => 'Plain HTTP URLs are not allowed by policy.',
            ]);

        $contextServer->refresh();
        $this->assertSame('https://agents.example/mcp', $contextServer->endpoint_url);
    }
}
