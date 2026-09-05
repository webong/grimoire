<?php

namespace Webong\LaravelGouge\Tests\Feature;

use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use App\Models\Server\UserClient;
use Figurate\AccountManager\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Webong\LaravelGouge\Models\Server\AgentConversation;
use Webong\LaravelGouge\Models\Server\ThreadActorSession;
use Webong\LaravelGouge\Support\AgentEvents;
use Webong\LaravelGouge\Support\Orchestrate\TaskRecord;

class WidgetAgentDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_login_does_not_reassign_agent_records_owned_by_the_widget_user(): void
    {
        $account = Account::query()->create([
            'name' => 'Existing Owner',
            'status' => 'active',
        ]);
        $subjectUser = User::query()->create([
            'name' => 'Existing Owner',
            'email' => 'agent-data-owner@example.com',
            'password' => 'password123',
            'type' => User::TypeSubject,
            'status' => 'active',
        ]);
        $account->users()->attach($subjectUser->id, [
            'type' => 'owner',
            'is_primary' => true,
            'linked_at' => now(),
        ]);

        $widgetUser = $this->makeWidgetUser();
        $space = Space::query()->create(['status' => 'open']);
        SpaceActorState::query()->create([
            'space_id' => $space->id,
            'thread_id' => null,
            'actorable_type' => $widgetUser->getMorphClass(),
            'actorable_id' => $widgetUser->id,
            'status' => SpaceActorState::StatusActive,
        ]);
        $thread = $space->threads()->create([
            'title' => 'Anonymous planning',
            'purpose' => 'planning',
            'phase' => 'draft',
            'status' => 'open',
        ]);
        $threadActor = ThreadActor::query()->create([
            'thread_id' => $thread->id,
            'actorable_type' => $widgetUser->getMorphClass(),
            'actorable_id' => $widgetUser->id,
            'role' => ThreadActor::RoleMember,
            'status' => ThreadActor::StatusActive,
            'priority' => 1,
            'config' => null,
        ]);
        $conversation = AgentConversation::query()->create([
            'id' => 'conv-widget-agent-data-1',
            'participant_type' => $widgetUser->getMorphClass(),
            'participant_id' => $widgetUser->id,
            'title' => 'Anonymous conversation',
        ]);
        $session = ThreadActorSession::query()->create([
            'thread_id' => $thread->id,
            'thread_actor_id' => $threadActor->id,
            'user_id' => $widgetUser->id,
            'conversation_id' => $conversation->id,
            'provider' => 'openai',
            'model' => 'gpt-test',
        ]);
        $taskEvent = ThreadEvent::query()->create([
            'thread_id' => $thread->id,
            'thread_actor_id' => $threadActor->id,
            'post_id' => null,
            'event_key' => 'agent_task',
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::Acp,
            'operation' => 'task.snapshot',
            'state' => 'submitted',
            'payload' => [
                'task' => [
                    'uuid' => (string) fake()->uuid(),
                    'public_id' => (string) fake()->uuid(),
                    'status' => 'submitted',
                    'user_id' => $widgetUser->id,
                    'user_uuid' => $widgetUser->uuid,
                ],
            ],
        ]);

        $this->withHeader('X-Widget-User-ID', (string) $widgetUser->uuid)
            ->postJson('/api/auth/login', [
                'email' => 'agent-data-owner@example.com',
                'password' => 'password123',
            ])
            ->assertOk()
            ->assertJsonPath('user.id', $subjectUser->id);

        $task = TaskRecord::fromEvent($taskEvent->fresh());

        $this->assertSame($widgetUser->getMorphClass(), $conversation->fresh()->participant_type);
        $this->assertSame($widgetUser->id, $conversation->fresh()->participant_id);
        $this->assertSame($widgetUser->id, $session->fresh()->user_id);
        $this->assertInstanceOf(TaskRecord::class, $task);
        $this->assertSame($widgetUser->id, $task->userId);
    }

    protected function makeWidgetUser(): User
    {
        $user = User::query()->create([
            'name' => 'Widget User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'type' => User::TypeWidget,
            'status' => 'active',
        ]);

        UserClient::query()->create([
            'user_id' => $user->id,
            'kind' => 'api',
            'device_identifier' => 'widget-agent-data-1',
            'last_seen_at' => now(),
        ]);

        return $user;
    }
}
