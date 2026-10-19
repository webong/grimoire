<?php

namespace Webong\Gouge\Tests\Feature;

use App\Features\Actions\Chat\EnqueueThreadMessageOutbox;
use App\Features\Actions\Chat\Protocols\ChannelProtocol;
use App\Jobs\DeliverOutboxMessage;
use App\Models\Server\Channel;
use App\Models\Server\Outbox;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Webong\Gouge\Support\AgentArtifacts;

class EnqueueThreadMessageOutboxChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enqueues_channel_outbox_records_for_active_thread_channel_addresses(): void
    {
        Queue::fake();

        $sender = User::factory()->create();
        $space = Space::factory()->create();
        $thread = $space->threads()->create([
            'purpose' => Thread::PurposeMain,
            'title' => 'Channel Outbox Thread',
            'phase' => 'execution',
            'status' => 'open',
        ]);
        $channel = Channel::factory()->create([
            'driver' => Channel::ProtocolGeneric,
        ]);

        app(ChannelLinkRepository::class)->create($channel, $thread, $thread, [
            'direction' => Channel::DirectionOutbound,
        ]);
        $route = $channel->routes()->create([
            'name' => 'primary',
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionOutbound,
            'config' => [
                'outbound' => [
                    'transport' => Channel::TransportHttp,
                    'endpoint_url' => 'https://channels.example/send',
                ],
            ],
            'data' => [
                'session' => 'default',
            ],
            'meta' => [],
        ]);
        $skill = $space->posts()->create([
            'type' => AgentArtifacts::SkillType,
            'tag' => 'waha-http-send',
            'status' => Post::StatusActive,
            'data' => [
                'text' => 'Use chatId and text for outbound delivery.',
                'slug' => 'waha-http-send',
                'name' => 'WAHA HTTP Send',
                'description' => 'Shape outbound WAHA HTTP payloads.',
            ],
            'meta' => [],
        ]);
        $skill->attachRelation($thread, AgentArtifacts::SkillRelation);
        $address = $route->addresses()->create([
            'addressable_type' => $thread->getMorphClass(),
            'addressable_id' => $thread->getKey(),
            'provider' => 'waha',
            'target' => '2348012345678@c.us',
            'target_type' => 'whatsapp_chat',
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionOutbound,
            'data' => [
                'phone' => '+2348012345678',
            ],
            'meta' => [],
        ]);

        $post = $thread->posts()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'data' => [
                'text' => 'External delivery payload',
                'message_type' => 'text',
            ],
            'meta' => [
                'source' => 'peer_message',
            ],
        ]);
        $post->attachRelation($sender, Post::RelationRoleSender);

        $created = app(EnqueueThreadMessageOutbox::class)->execute($post);

        $this->assertCount(1, $created);
        $outbox = $created->first();
        $this->assertInstanceOf(Outbox::class, $outbox);
        $this->assertSame(ChannelProtocol::Key, $outbox->protocol);
        $this->assertSame('waha', $outbox->provider);
        $this->assertSame('2348012345678@c.us', $outbox->target);
        $this->assertSame('thread.post.created', data_get($outbox->payload, 'event'));
        $this->assertSame($channel->uuid, data_get($outbox->payload, 'channel.id'));
        $this->assertSame($route->ulid, data_get($outbox->payload, 'route.id'));
        $this->assertSame($address->ulid, data_get($outbox->payload, 'address.id'));
        $this->assertSame('2348012345678@c.us', data_get($outbox->payload, 'address.target'));
        $this->assertSame($sender->uuid, data_get($outbox->payload, 'sender.id'));
        $this->assertSame('2348012345678@c.us', data_get($outbox->payload, 'recipients.0.target'));
        $this->assertSame('https://channels.example/send', data_get($outbox->payload, 'delivery.route.config.outbound.endpoint_url'));
        $this->assertSame('waha-http-send', data_get($outbox->payload, 'delivery.skill_context.entries.0.slug'));

        Queue::assertPushed(DeliverOutboxMessage::class, function (DeliverOutboxMessage $job) use ($outbox): bool {
            return $job->outboxId === $outbox->id;
        });

        $invocationPost = $thread->posts()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'data' => ['text' => 'Invocation output is ready.'],
            'meta' => [
                'source' => 'agent_response',
                'invocation_id' => 'invocation-42',
            ],
        ]);
        $invocationPost->attachRelation($sender, Post::RelationRoleSender);

        $invocationOutbox = app(EnqueueThreadMessageOutbox::class)
            ->execute($invocationPost)
            ->first();

        $this->assertInstanceOf(Outbox::class, $invocationOutbox);
        $this->assertSame('invocation.available', data_get($invocationOutbox->payload, 'event'));
        $this->assertSame('invocation-42', data_get($invocationOutbox->payload, 'invocation.id'));
        $this->assertSame($invocationPost->ulid, data_get($invocationOutbox->payload, 'invocation.node.id'));
        $this->assertStringEndsWith(
            '/api/form/invocation-42/turns',
            (string) data_get($invocationOutbox->payload, 'invocation.turns_url'),
        );
    }
}
