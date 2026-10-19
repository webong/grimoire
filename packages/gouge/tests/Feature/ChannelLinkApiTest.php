<?php

namespace Webong\Gouge\Tests\Feature;

use App\Models\Server\Channel;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\Thread;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use App\TokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Webong\Gouge\Ai\Support\Mcp\McpRegistry;
use Webong\Gouge\Support\AgentChannels;

class ChannelLinkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_a_channel_post_to_a_thread_through_the_edge_api(): void
    {
        $user = User::factory()->create();
        $space = Space::factory()->create();
        $thread = $space->threads()->create([
            'title' => 'Tool work',
            'purpose' => Thread::PurposeMain,
            'phase' => Thread::PhaseInitial,
            'status' => 'open',
        ]);
        SpaceActorState::query()->create([
            'space_id' => $space->id,
            'thread_id' => $thread->id,
            'actorable_type' => $user->getMorphClass(),
            'actorable_id' => $user->id,
            'status' => SpaceActorState::StatusActive,
        ]);

        Sanctum::actingAs($user, [TokenAbility::NodesWrite->value, TokenAbility::EdgesWrite->value]);

        $channel = Channel::factory()->create([
            'driver' => AgentChannels::Mcp,
            'server' => 'filesystem',
            'transport' => Channel::TransportHttp,
            'endpoint_url' => 'https://tools.example/mcp',
        ]);
        app(ChannelLinkRepository::class)->create($channel, $space, $space);

        $linkResponse = $this->postJson('/api/posts', [
            'parent' => [
                'type' => 'space',
                'id' => $space->uuid,
            ],
            'attributes' => [
                'post_type' => Post::TypeChannelLink,
                'tag' => $channel->uuid,
                'payload' => [
                    'direction' => Channel::DirectionOutbound,
                    'config' => [
                        'mode' => 'remote',
                    ],
                ],
            ],
            'relations' => [
                [
                    'role' => Post::RelationRoleChannel,
                    'target' => [
                        'type' => 'channel',
                        'id' => $channel->uuid,
                    ],
                ],
            ],
        ])->assertCreated();

        $linkId = (string) $linkResponse->json('data.id');

        $this->postJson('/api/edges', [
            'source_type' => 'post',
            'source_id' => $linkId,
            'target_type' => 'thread',
            'target_id' => $thread->uuid,
            'edge_type' => Post::RelationRoleChannelLink,
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', Post::RelationRoleChannelLink)
            ->assertJsonPath('data.source.id', $linkId)
            ->assertJsonPath('data.target.id', $thread->uuid);

        $resolved = app(McpRegistry::class)->resolve('filesystem', $thread, $user);

        $this->assertTrue($resolved['enabled']);
        $this->assertSame('https://tools.example/mcp', $resolved['endpoint_url']);
        $this->assertSame('Thread', $resolved['context_source']);
    }

    public function test_the_legacy_channel_connection_routes_are_removed(): void
    {
        $user = User::factory()->create();
        $channel = Channel::factory()->create();

        Sanctum::actingAs($user, [TokenAbility::ChannelsManage->value]);

        $this->postJson("/api/channels/{$channel->uuid}/connections", [])
            ->assertNotFound();
    }
}
