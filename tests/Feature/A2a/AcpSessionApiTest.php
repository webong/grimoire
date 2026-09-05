<?php

namespace Webong\LaravelGouge\Tests\Feature\A2a;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Database\Factories\SpaceFactory;
use Figurate\Auth\Support\RobotUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;
use Webong\LaravelGouge\Contracts\AgentRuntime;
use Webong\LaravelGouge\Support\AgentActors;
use Webong\LaravelGouge\Support\AgentEvents;
use Webong\LaravelGouge\Support\Orchestrate\TaskRecord;

class AcpSessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_lists_and_loads_acp_sessions(): void
    {
        $user = $this->makeUser(RobotUsers::Robot);
        $space = $this->accessibleSpace($user);

        Sanctum::actingAs($user, ['acp:use']);

        $response = $this->postJson('/api/acp/sessions', [
            'space_uuid' => $space->uuid,
            'title' => 'ACP Build Session',
            'purpose' => 'code_review',
            'phase' => 'checking_changes',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'ACP Build Session')
            ->assertJsonPath('data.space.id', $space->uuid);

        $sessionId = (string) $response->json('data.id');
        $thread = Thread::query()->where('uuid', $sessionId)->firstOrFail();

        $this->assertDatabaseHas('thread_actors', [
            'thread_id' => $thread->id,
            'actorable_type' => AgentActors::Coordinator,
            'role' => AgentActors::RolePresenter,
            'status' => ThreadActor::StatusActive,
        ]);
        $this->assertSame('code_review', $thread->purpose);
        $this->assertSame('checking_changes', $thread->phase);

        $message = Post::query()->create([
            'postable_type' => $thread->getMorphClass(),
            'postable_id' => $thread->id,
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'text' => 'Please inspect the workspace.',
            'meta' => ['source' => 'acp_prompt'],
        ]);
        $message->attachRelation($user, Post::RelationRoleSender);

        Post::query()->create([
            'postable_type' => $thread->getMorphClass(),
            'postable_id' => $thread->id,
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'text' => 'Inspection complete.',
            'meta' => [
                'source' => 'agent_response',
                'actor_key' => AgentActors::Coordinator,
            ],
        ]);

        $this->getJson('/api/acp/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sessionId);

        $this->getJson("/api/acp/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.messages.1.role', 'assistant')
            ->assertJsonPath('data.messages.1.text', 'Inspection complete.');
    }

    public function test_it_prompts_and_cancels_acp_tasks(): void
    {
        $this->mock(AgentRuntime::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitTurn')->once();
        });

        $user = $this->makeUser(RobotUsers::Robot);
        $space = $this->accessibleSpace($user);

        Sanctum::actingAs($user, ['acp:use']);

        $sessionResponse = $this->postJson('/api/acp/sessions', [
            'space_uuid' => $space->uuid,
            'title' => 'Cancelable ACP Session',
            'purpose' => 'execution',
        ])->assertCreated();

        $sessionId = (string) $sessionResponse->json('data.id');

        $promptResponse = $this->postJson("/api/acp/sessions/{$sessionId}/prompt", [
            'text' => 'Run the task.',
        ]);

        $promptResponse->assertAccepted()
            ->assertJsonPath('data.session_id', $sessionId)
            ->assertJsonPath('data.state', 'submitted');

        $taskId = (string) $promptResponse->json('data.id');
        $task = TaskRecord::fromEvent(
            ThreadEvent::query()->where('event_key', 'agent_task')->latest('id')->firstOrFail()
        );
        $this->assertInstanceOf(TaskRecord::class, $task);
        $this->assertSame(ThreadEvent::LayerExecution, $task->event->layer);
        $this->assertSame(AgentEvents::Acp, $task->event->kind);
        $this->assertSame('task.snapshot', $task->event->operation);
        $this->assertSame('submitted', $task->event->state);

        $this->assertSame('submitted', $task->status);
        $this->assertNotNull($task->message?->id);
        $this->assertSame($user->id, $task->userId);

        $this->getJson("/api/acp/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('data.id', $taskId)
            ->assertJsonPath('data.state', 'submitted');

        $this->postJson("/api/acp/tasks/{$taskId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.id', $taskId)
            ->assertJsonPath('data.state', 'canceled');

        $task = TaskRecord::fromEvent(
            ThreadEvent::query()->where('event_key', 'agent_task')->latest('id')->firstOrFail()
        );
        $this->assertInstanceOf(TaskRecord::class, $task);
        $this->assertSame(ThreadEvent::LayerExecution, $task->event->layer);
        $this->assertSame(AgentEvents::Acp, $task->event->kind);
        $this->assertSame('task.canceled', $task->event->operation);
        $this->assertSame('canceled', $task->event->state);
        $this->assertSame('canceled', $task->status);
        $this->assertNotNull($task->canceledAt);

        $this->getJson("/api/acp/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('data.state', 'canceled');
    }

    public function test_an_agent_user_can_access_acp_with_passport_authentication(): void
    {
        $user = $this->makeUser(RobotUsers::Robot);
        $space = $this->accessibleSpace($user);

        Passport::actingAs($user, ['acp:use'], 'passport');

        $this->postJson('/api/acp/sessions', [
            'space_uuid' => $space->uuid,
            'title' => 'Passport ACP Session',
            'purpose' => 'execution',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Passport ACP Session')
            ->assertJsonPath('data.space.id', $space->uuid);
    }

    protected function accessibleSpace(User $user): Space
    {
        $space = SpaceFactory::new()->create();

        SpaceActorState::query()->create([
            'space_id' => $space->id,
            'thread_id' => null,
            'actorable_type' => $user->getMorphClass(),
            'actorable_id' => $user->id,
            'status' => SpaceActorState::StatusActive,
        ]);

        return $space;
    }

    protected function makeUser(string $type = User::TypeSubject): User
    {
        return User::query()->create([
            'name' => 'ACP Tester',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'type' => $type,
            'status' => 'active',
        ]);
    }
}
