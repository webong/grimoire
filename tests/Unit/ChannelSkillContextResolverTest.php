<?php

namespace Webong\Gouge\Tests\Unit;

use App\Models\Server\Channel;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Webong\Gouge\Support\AgentArtifacts;
use Webong\Gouge\Support\Channels\ChannelSkillContextResolver;

class ChannelSkillContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_post_backed_skills_attached_to_channel_space_thread_and_post_contexts(): void
    {
        $space = Space::factory()->create();
        $thread = $space->threads()->create([
            'title' => 'Support',
            'purpose' => Thread::PurposeMain,
            'phase' => Thread::PhaseInitial,
            'status' => 'open',
        ]);
        $channel = Channel::factory()->create([
            'driver' => Channel::ProtocolGeneric,
        ]);
        $route = $channel->routes()->create([
            'config' => [],
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionBidirectional,
            'data' => [],
            'meta' => [],
        ]);
        $address = $route->addresses()->create([
            'addressable_type' => $thread->getMorphClass(),
            'addressable_id' => $thread->getKey(),
            'provider' => 'generic',
            'target' => 'target-123',
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionBidirectional,
            'data' => [],
            'meta' => [],
        ]);
        $outboundPost = $thread->posts()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'data' => ['text' => 'Deliver this message.'],
            'meta' => [],
        ]);

        $this->createSkill($space, 'channel-skill')->attachRelation($channel, AgentArtifacts::SkillRelation);
        $this->createSkill($space, 'space-skill')->attachRelation($space, AgentArtifacts::SkillRelation);
        $this->createSkill($space, 'thread-skill')->attachRelation($thread, AgentArtifacts::SkillRelation);
        $this->createSkill($space, 'post-skill')->attachRelation($outboundPost, AgentArtifacts::SkillRelation);

        $resolved = app(ChannelSkillContextResolver::class)
            ->resolve($channel, $route, $address, $outboundPost);

        $this->assertSame('channel-skill', data_get($resolved, 'channel.0.slug'));
        $this->assertSame('space-skill', data_get($resolved, 'space.0.slug'));
        $this->assertSame('thread-skill', data_get($resolved, 'thread.0.slug'));
        $this->assertSame('post-skill', data_get($resolved, 'post.0.slug'));
        $this->assertCount(4, $resolved['entries']);
    }

    protected function createSkill(Space $space, string $slug): Post
    {
        return $space->posts()->create([
            'type' => AgentArtifacts::SkillType,
            'tag' => $slug,
            'status' => Post::StatusActive,
            'data' => [
                'text' => "Use {$slug}.",
                'slug' => $slug,
                'name' => $slug,
                'description' => "{$slug} description",
            ],
            'meta' => [],
        ]);
    }
}
