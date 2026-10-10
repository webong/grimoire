<?php

namespace Webong\Gouge\Tests\Feature;

use App\Models\Server\Channel;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\WebhookClient\Models\WebhookCall;
use Tests\TestCase;
use Webong\Gouge\Support\AgentArtifacts;

class ChannelRouteIngressApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_inbound_messages_for_a_thread_address(): void
    {
        $user = User::factory()->create();
        $channel = Channel::factory()->create([
            'driver' => Channel::ProtocolGeneric,
            'server' => 'whatsapp-waha',
        ]);
        $space = Space::factory()->create();
        $thread = $space->threads()->create([
            'purpose' => Thread::PurposeMain,
            'title' => 'WhatsApp Support',
            'phase' => 'open',
            'status' => 'open',
        ]);
        app(ChannelLinkRepository::class)->create($channel, $space, $thread);
        $route = $channel->routes()->create([
            'name' => 'default-session',
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionBidirectional,
            'config' => [
                'inbound' => [
                    'transport' => Channel::TransportWebhook,
                    'auth' => [
                        'type' => 'header',
                        'header' => 'X-Channel-Key',
                        'secret' => 'route-secret',
                    ],
                ],
            ],
            'data' => [],
            'meta' => [],
        ]);
        $skill = $space->posts()->create([
            'type' => AgentArtifacts::SkillType,
            'tag' => 'waha-webhook-ingest',
            'status' => Post::StatusActive,
            'data' => [
                'text' => 'Map chatId to the provider target and text to the inbound message body.',
                'slug' => 'waha-webhook-ingest',
                'name' => 'WAHA Webhook Ingest',
                'description' => 'Normalize WAHA inbound webhook payloads.',
            ],
            'meta' => [],
        ]);
        $skill->attachRelation($channel, AgentArtifacts::SkillRelation);
        $address = $route->addresses()->create([
            'addressable_type' => $thread->getMorphClass(),
            'addressable_id' => $thread->getKey(),
            'provider' => 'waha',
            'target' => '2348012345678@c.us',
            'target_type' => 'whatsapp_chat',
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionBidirectional,
            'data' => [],
            'meta' => [],
        ]);

        $response = $this->withHeaders([
            'X-Channel-Key' => 'route-secret',
        ])->postJson(route('webhook-client-channel_route_inbound', ['route' => $route->id]), [
            'id' => 'msg-123',
            'chatId' => '2348012345678@c.us',
            'from' => 'external-user-1',
            'text' => 'Hello from WhatsApp',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'ok');

        $this->assertDatabaseHas('webhook_calls', [
            'name' => 'channel_route_inbound',
        ]);

        $webhookCall = WebhookCall::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString('/channel-routes/'.$route->id.'/inbound', $webhookCall->url);

        $post = Post::query()
            ->forThread($thread)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('Hello from WhatsApp', $post->text);
        $this->assertSame('generic_inbound', data_get($post->meta, 'source'));
        $this->assertSame('2348012345678@c.us', data_get($post->meta, 'external_payload.target'));
        $this->assertSame('waha-webhook-ingest', data_get($post->meta, 'external_payload.skill_context.entries.0.slug'));
    }

    public function test_it_creates_a_thread_from_a_space_address_when_no_thread_address_exists(): void
    {
        $user = User::factory()->create();
        $channel = Channel::factory()->create([
            'driver' => Channel::ProtocolGeneric,
            'server' => 'vendor-webhook',
        ]);
        $space = Space::factory()->create();
        app(ChannelLinkRepository::class)->create($channel, $space, $space);
        $route = $channel->routes()->create([
            'name' => 'support-ingress',
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionBidirectional,
            'config' => [
                'inbound' => [
                    'transport' => Channel::TransportWebhook,
                ],
            ],
            'data' => [],
            'meta' => [],
        ]);
        $route->addresses()->create([
            'addressable_type' => $space->getMorphClass(),
            'addressable_id' => $space->getKey(),
            'provider' => 'generic',
            'target' => 'support-default',
            'target_type' => 'mailbox',
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionBidirectional,
            'data' => [],
            'meta' => [],
        ]);

        $response = $this->postJson(route('webhook-client-channel_route_inbound', ['route' => $route->id]), [
            'id' => 'msg-456',
            'target' => 'customer-42',
            'sender' => 'external-user-2',
            'text' => 'Need help with my order',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'ok');

        $this->assertDatabaseHas('webhook_calls', [
            'name' => 'channel_route_inbound',
        ]);

        $thread = Thread::query()
            ->where('threadable_type', $space->getMorphClass())
            ->where('threadable_id', $space->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($space->getMorphClass(), $thread->threadable_type);
        $this->assertSame($space->id, $thread->threadable_id);
        $this->assertTrue($route->addresses()
            ->where('addressable_type', $thread->getMorphClass())
            ->where('addressable_id', $thread->getKey())
            ->where('target', 'customer-42')
            ->exists());
    }
}
