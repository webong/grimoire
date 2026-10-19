<?php

namespace Webong\Gouge\Tests\Unit;

use App\Models\Server\Post;
use App\Models\Server\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;
use Webong\Gouge\Ai\Tools\DiscoverSkillsTool;
use Webong\Gouge\Support\AgentArtifacts;

class DiscoverSkillsToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_discovers_post_backed_skills(): void
    {
        $space = Space::factory()->create();
        $skill = $space->posts()->create([
            'type' => AgentArtifacts::SkillType,
            'tag' => 'support-messaging',
            'status' => Post::StatusActive,
            'data' => [
                'text' => 'Normalize targets before delivering support messages.',
                'slug' => 'support-messaging',
                'name' => 'Support messaging',
                'description' => 'Format and normalize support messages.',
            ],
            'meta' => [],
        ]);

        $response = json_decode((string) (new DiscoverSkillsTool)->handle(new ToolRequest([
            'query' => 'support',
            'limit' => 5,
            'include_content' => true,
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($response['ok']);
        $this->assertSame(1, $response['count']);
        $this->assertSame('post', $response['skills'][0]['source']);
        $this->assertSame($skill->ulid, $response['skills'][0]['post_id']);
        $this->assertSame('support-messaging', $response['skills'][0]['slug']);
        $this->assertStringContainsString('Normalize targets', $response['skills'][0]['content_excerpt']);
    }
}
