<?php

namespace Webong\Gouge\Tests\Feature\A2a;

use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Database\Factories\SpaceFactory;
use Figurate\Auth\Support\RobotUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;
use Webong\Gouge\Ai\Support\A2a\A2aMethodRouter;
use Webong\Gouge\Contracts\AgentRuntime;
use Webong\Gouge\Support\AgentEvents;
use Webong\Gouge\Support\Orchestrate\TaskRecord;

class A2aTaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_a2a_tasks_in_thread_events(): void
    {
        $this->mock(AgentRuntime::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitTurn')->once();
        });

        $user = $this->makeUser(RobotUsers::Robot);
        $space = $this->accessibleSpace($user);

        Sanctum::actingAs($user, [
            'a2a:message.send',
            'a2a:task.read',
            'a2a:task.cancel',
        ]);

        $router = app(A2aMethodRouter::class);

        $sendResponse = $router->handle('message/send', [
            'user_uuid' => $user->uuid,
            'space' => $space->uuid,
            'content' => [
                'text' => 'Run the A2A task.',
            ],
        ]);

        $this->assertArrayHasKey('result', $sendResponse);
        $this->assertSame('submitted', data_get($sendResponse, 'result.task.status.state'));

        $taskId = (string) data_get($sendResponse, 'result.task.id');
        $task = TaskRecord::fromEvent(
            ThreadEvent::query()->where('event_key', 'agent_task')->latest('id')->firstOrFail()
        );
        $this->assertInstanceOf(TaskRecord::class, $task);
        $this->assertSame(ThreadEvent::LayerExecution, $task->event->layer);
        $this->assertSame(AgentEvents::A2a, $task->event->kind);
        $this->assertSame('task.snapshot', $task->event->operation);
        $this->assertSame('submitted', $task->event->state);

        $this->assertSame('submitted', $task->status);
        $this->assertSame($taskId, $task->publicId);
        $this->assertSame('a2a', $task->protocol);
        $this->assertSame($user->getMorphClass(), data_get($task->owner, 'subject_type'));
        $this->assertSame((string) $user->id, (string) data_get($task->owner, 'subject_id'));

        $getResponse = $router->handle('tasks/get', [
            'taskId' => $taskId,
        ]);

        $this->assertArrayHasKey('result', $getResponse);
        $this->assertSame($taskId, data_get($getResponse, 'result.task.id'));
        $this->assertSame('submitted', data_get($getResponse, 'result.task.status.state'));

        $cancelResponse = $router->handle('tasks/cancel', [
            'taskId' => $taskId,
        ]);

        $this->assertArrayHasKey('result', $cancelResponse);
        $this->assertSame($taskId, data_get($cancelResponse, 'result.task.id'));
        $this->assertSame('canceled', data_get($cancelResponse, 'result.task.status.state'));

        $task = TaskRecord::fromEvent(
            ThreadEvent::query()->where('event_key', 'agent_task')->latest('id')->firstOrFail()
        );
        $this->assertInstanceOf(TaskRecord::class, $task);
        $this->assertSame(ThreadEvent::LayerExecution, $task->event->layer);
        $this->assertSame(AgentEvents::A2a, $task->event->kind);
        $this->assertSame('task.canceled', $task->event->operation);
        $this->assertSame('canceled', $task->event->state);
        $this->assertSame('canceled', $task->status);
        $this->assertNotNull($task->canceledAt);
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
            'name' => 'A2A Tester',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'type' => $type,
            'status' => 'active',
        ]);
    }
}
