<?php

namespace Webong\LaravelGouge\Tests;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Webong\LaravelGouge\Contracts\AgentRuntime;
use Webong\LaravelGouge\Runtime\AgentTurn;
use Webong\LaravelGouge\Runtime\LaravelAiRuntime;
use Webong\LaravelGouge\Support\AgentActors;

class LaravelAiRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_adapter_owns_the_laravel_ai_dependency(): void
    {
        $rootComposer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $adapterComposer = json_decode((string) file_get_contents(base_path('ext/laravel-gouge/composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('laravel/ai', $rootComposer['require']);
        $this->assertSame('^0.10.1', $adapterComposer['require']['laravel/ai']);
    }

    public function test_it_registers_as_the_optional_agent_runtime(): void
    {
        $this->assertInstanceOf(LaravelAiRuntime::class, $this->app->make(AgentRuntime::class));
    }

    public function test_it_builds_stable_runtime_correlation_from_a_figurate_turn(): void
    {
        $runtime = $this->app->make(LaravelAiRuntime::class);
        $turn = new AgentTurn(
            threadId: 10,
            threadUuid: 'thread-1',
            postId: 20,
            postUlid: 'post-1',
            userId: 30,
            threadActorId: 40,
            actorKey: 'coordinator_agent',
            prompt: 'Review this.',
            broadcastChannel: 'threads.thread-1',
            metadata: [
                'invocations' => [
                    'coordinator_agent' => [
                        'invocation_id' => 'invocation-1',
                        'conversation_id' => 'conversation-1',
                    ],
                ],
            ],
        );

        $first = $runtime->correlationFor($turn);
        $second = $runtime->correlationFor($turn);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('laravel-ai', $first->runtime);
        $this->assertSame('invocation-1', $first->invocationId);
        $this->assertSame('conversation-1', $first->conversationId);
    }

    public function test_it_cooperatively_cancels_and_streams_a_durable_turn_state(): void
    {
        $user = User::factory()->create();
        $space = Space::factory()->create();
        $thread = $space->threads()->create([
            'purpose' => Thread::PurposeMain,
            'title' => 'Runtime port test',
            'phase' => 'execution',
            'status' => 'open',
        ]);
        $presenter = $thread->actors()->create([
            'actorable_type' => AgentActors::Coordinator,
            'actorable_id' => null,
            'role' => AgentActors::RolePresenter,
            'status' => ThreadActor::StatusActive,
            'priority' => 1,
            'config' => null,
        ]);
        $post = $thread->posts()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'data' => [
                'text' => 'Review this.',
                'message_type' => 'text',
            ],
            'senderable_type' => $user->getMorphClass(),
            'senderable_id' => $user->id,
            'meta' => [
                'source' => 'agent_prompt',
                'invocations' => [
                    AgentActors::Coordinator => [
                        'status' => 'pending',
                        'invocation_id' => 'invocation-1',
                    ],
                ],
            ],
        ]);
        $turn = AgentTurn::forPrompt(
            thread: $thread,
            post: $post,
            user: $user,
            threadActor: $presenter,
            broadcastChannel: "threads.{$thread->uuid}",
        );
        $runtime = $this->app->make(LaravelAiRuntime::class);
        $correlation = $runtime->correlationFor($turn);

        $cancellation = $runtime->cancel($correlation);
        $events = iterator_to_array($runtime->streamEvents($correlation));

        $this->assertTrue($cancellation->accepted);
        $this->assertFalse($cancellation->interrupted);
        $this->assertSame('canceled', data_get($post->fresh()->meta, 'invocations.coordinator_agent.status'));
        $this->assertCount(1, $events);
        $this->assertSame('turn.status', $events[0]->type);
        $this->assertSame('canceled', $events[0]->payload['status']);
    }
}
