<?php

namespace Webong\Gouge\Tests\Feature\Conversation;

use App\Features\Actions\Chat\DispatchThreadMessage;
use App\Features\Actions\Chat\ThreadMessageEntry;
use App\Jobs\DeliverOutboxMessage;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use App\TokenAbility;
use Database\Factories\SpaceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request as AiToolRequest;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;
use Webong\Gouge\Ai\Support\SubAgents\SubAgentDispatcher;
use Webong\Gouge\Ai\Support\SubAgents\SubAgentInvocationMemory;
use Webong\Gouge\Ai\Tools\InvokeSubAgentTool;
use Webong\Gouge\Contracts\AgentRuntime;
use Webong\Gouge\Jobs\ProcessThreadObservers;
use Webong\Gouge\Models\Server\AgentConversation;
use Webong\Gouge\Models\Server\AgentConversationMessage;
use Webong\Gouge\Models\Server\ThreadActorSession;
use Webong\Gouge\Support\AgentActors;

class HandleConversationMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_responses_keep_their_source_metadata_in_presenter_threads(): void
    {
        $space = Space::factory()->create();
        $thread = $this->makeThread($space);
        $this->addThreadActor($thread, AgentActors::RolePresenter, AgentActors::Coordinator);

        $message = app(DispatchThreadMessage::class)->execute(ThreadMessageEntry::systemMessage(
            thread: $thread,
            text: 'The requested review is complete.',
            source: 'agent_response',
        ));

        $this->assertSame('agent_response', data_get($message->meta, 'source'));
    }

    public function test_it_queues_presenters_for_presenter_threads_without_observer_dispatch(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $this->makeThread($space);
        $this->addThreadActor($thread, AgentActors::RolePresenter, AgentActors::Coordinator);

        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $this->postJson('/api/form', [
            'body' => [
                'type' => 'post',
                'parent' => [
                    'type' => 'thread',
                    'id' => $thread->uuid,
                ],
                'attributes' => [
                    'post_type' => Post::TypeMessage,
                    'text' => 'Please help me scope the repair.',
                ],
            ],
        ])
            ->assertAccepted()
            ->assertJsonPath('space', $space->uuid)
            ->assertJsonPath('interaction_mode', 'presenter')
            ->assertJsonPath('observer_status', 'none')
            ->assertJsonPath('pending', true)
            ->assertJsonPath('pending_presenters', 1)
            ->assertJsonMissingPath('channel');

        $message = Post::query()->messageType()->latest('id')->firstOrFail();

        $this->assertSame('agent_prompt', data_get($message->meta, 'source'));
        $this->assertFalse((bool) data_get($message->meta, 'observer_dispatch'));
        Queue::assertPushed(DeliverOutboxMessage::class, 1);
    }

    public function test_it_queues_observers_for_peer_threads(): void
    {
        Queue::fake();

        $this->mock(AgentRuntime::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submitTurn');
        });

        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $this->makeThread($space);
        $this->addThreadActor($thread, AgentActors::RoleObserver, AgentActors::SafetyGuard);

        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $this->postJson('/api/form', [
            'body' => [
                'type' => 'post',
                'parent' => [
                    'type' => 'thread',
                    'id' => $thread->uuid,
                ],
                'attributes' => [
                    'post_type' => Post::TypeMessage,
                    'text' => 'I have shared the details with the artisan.',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('interaction_mode', 'peer')
            ->assertJsonPath('observer_status', 'queued')
            ->assertJsonPath('pending', false)
            ->assertJsonPath('pending_presenters', 0);

        $message = Post::query()->messageType()->latest('id')->firstOrFail();

        $this->assertSame('peer_message', data_get($message->meta, 'source'));
        $this->assertTrue((bool) data_get($message->meta, 'observer_dispatch'));
        Queue::assertPushed(ProcessThreadObservers::class, 1);
    }

    public function test_it_supports_hybrid_threads_by_queueing_both_presenters_and_observers(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $this->makeThread($space);
        $this->addThreadActor($thread, AgentActors::RolePresenter, AgentActors::Coordinator);
        $this->addThreadActor($thread, AgentActors::RoleObserver, AgentActors::SafetyGuard, 2);

        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $this->postJson('/api/form', [
            'body' => [
                'type' => 'post',
                'parent' => [
                    'type' => 'thread',
                    'id' => $thread->uuid,
                ],
                'attributes' => [
                    'post_type' => Post::TypeMessage,
                    'text' => 'We are confirming the visit window now.',
                ],
            ],
        ])
            ->assertAccepted()
            ->assertJsonPath('interaction_mode', 'hybrid')
            ->assertJsonPath('observer_status', 'queued')
            ->assertJsonPath('pending', true)
            ->assertJsonPath('pending_presenters', 1);

        $message = Post::query()->messageType()->latest('id')->firstOrFail();

        $this->assertSame('agent_prompt', data_get($message->meta, 'source'));
        $this->assertTrue((bool) data_get($message->meta, 'observer_dispatch'));
        Queue::assertPushed(ProcessThreadObservers::class, 1);
        Queue::assertPushed(DeliverOutboxMessage::class, 1);
    }

    public function test_it_returns_turns_for_a_thread_message_from_the_dedicated_endpoint(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $this->makeThread($space);
        $invocationId = (string) fake()->uuid();

        $promptMessage = $thread->messages()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'text' => 'Please help me scope the repair.',
            'meta' => [
                'source' => 'agent_prompt',
                'invocations' => [
                    'coordinator_agent' => [
                        'invocation_id' => $invocationId,
                        'status' => 'completed',
                    ],
                ],
            ],
        ]);
        $promptMessage->attachRelation($user, Post::RelationRoleSender);

        $assistantMessage = $thread->messages()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'text' => 'Start by listing the visible damage and confirming access.',
            'meta' => [
                'source' => 'agent_response',
                'in_reply_to_post_id' => $promptMessage->id,
                'actor_key' => 'coordinator_agent',
                'invocation_id' => $invocationId,
            ],
        ]);

        $conversation = AgentConversation::query()->create([
            'id' => (string) fake()->uuid(),
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'Repair planning',
        ]);
        $agentMessage = new AgentConversationMessage;
        $agentMessage->forceFill([
            'id' => (string) fake()->uuid(),
            'conversation_id' => $conversation->id,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'agent' => AgentRuntime::class,
            'role' => 'assistant',
            'invocation_id' => $invocationId,
            'trace_id' => $invocationId,
            'parent_invocation_id' => null,
            'invocable_type' => $promptMessage->getMorphClass(),
            'invocable_id' => $promptMessage->id,
            'content' => $assistantMessage->text,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => json_encode(['invocation_id' => $invocationId]),
        ])->save();
        $childInvocationId = (string) fake()->uuid();
        $childAgentMessage = new AgentConversationMessage;
        $childAgentMessage->forceFill([
            'id' => (string) fake()->uuid(),
            'conversation_id' => $conversation->id,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'agent' => 'researcher',
            'role' => 'assistant',
            'invocation_id' => $childInvocationId,
            'trace_id' => $invocationId,
            'parent_invocation_id' => $invocationId,
            'invocable_type' => $promptMessage->getMorphClass(),
            'invocable_id' => $promptMessage->id,
            'content' => 'The relevant constraint is documented.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ])->save();

        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $this->getJson(sprintf('/api/form/%s/turns', $invocationId))
            ->assertOk()
            ->assertJsonPath('invocation_id', $invocationId)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent_message_id', $agentMessage->id)
            ->assertJsonPath('data.0.invocation_id', $invocationId)
            ->assertJsonPath('data.0.trace_id', $invocationId)
            ->assertJsonPath('data.0.invocable.type', 'post')
            ->assertJsonPath('data.0.invocable.id', $promptMessage->ulid)
            ->assertJsonPath('data.0.children.0.agent_message_id', $childAgentMessage->id)
            ->assertJsonPath('data.0.children.0.parent_invocation_id', $invocationId)
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_it_persists_successful_sub_agent_executions_as_child_agent_messages(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $this->makeThread($space);
        $threadActor = $this->addThreadActor($thread, AgentActors::RolePresenter, AgentActors::Coordinator);
        $conversation = AgentConversation::query()->create([
            'id' => (string) fake()->uuid(),
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'Sub-agent execution',
        ]);
        ThreadActorSession::query()->create([
            'thread_id' => $thread->id,
            'thread_actor_id' => $threadActor->id,
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'provider' => 'default',
            'model' => 'default',
            'last_used_at' => now(),
        ]);
        $promptPost = $thread->messages()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'text' => 'Research the applicable constraint.',
            'meta' => ['source' => 'agent_prompt'],
        ]);
        $dispatcher = new class extends SubAgentDispatcher
        {
            public function dispatch(
                string $subAgentKey,
                string $prompt,
                array $context = [],
                ?string $traceId = null,
                ?string $parentInvocationId = null,
            ): array {
                return [
                    'ok' => true,
                    'sub_agent' => $subAgentKey,
                    'trace_id' => $traceId,
                    'invocation_id' => 'SUB-INV-1',
                    'parent_invocation_id' => $parentInvocationId,
                    'response' => [
                        'text' => 'The constraint is documented.',
                        'conversation_id' => null,
                        'provider_invocation_id' => 'PROVIDER-SUB-1',
                        'fallback_text' => null,
                    ],
                    'telemetry' => [
                        'agent' => 'researcher',
                        'tool_calls' => [['name' => 'search']],
                        'tool_results' => [['result' => 'found']],
                        'usage' => ['total_tokens' => 42],
                        'meta' => [],
                    ],
                ];
            }
        };
        $tool = new class($thread, $user, $threadActor, $dispatcher) extends InvokeSubAgentTool
        {
            public function __construct(
                Thread $thread,
                User $actor,
                ThreadActor $threadActor,
                SubAgentDispatcher $dispatcher,
            ) {
                parent::__construct($thread, $actor, $threadActor, $dispatcher, new SubAgentInvocationMemory);
            }

            protected function isSubAgentAllowedForActor(string $subAgent): bool
            {
                return true;
            }

            protected function readInvocationMemory(): array
            {
                return [
                    'trace_id' => null,
                    'parent_invocation_id' => null,
                    'last_sub_agent_invocation_id' => null,
                    'updated_at' => null,
                ];
            }

            protected function rememberInvocationContext(string $traceId, ?string $parentInvocationId, ?string $lastSubAgentInvocationId): void {}

            protected function recordInvocationEvent(string $subAgent, array $result, bool $successful): void {}
        };

        $result = json_decode((string) $tool->handle(new AiToolRequest([
            'sub_agent' => 'researcher',
            'prompt' => 'Research this.',
            'trace_id' => 'MAIN-INV-1',
            'parent_invocation_id' => 'MAIN-INV-1',
        ])), true);
        $childMessage = AgentConversationMessage::query()
            ->where('invocation_id', 'SUB-INV-1')
            ->firstOrFail();

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('telemetry', $result);
        $this->assertSame('MAIN-INV-1', $childMessage->trace_id);
        $this->assertSame('MAIN-INV-1', $childMessage->parent_invocation_id);
        $this->assertSame($promptPost->getMorphClass(), $childMessage->invocable_type);
        $this->assertSame($promptPost->id, $childMessage->invocable_id);
        $this->assertSame([['name' => 'search']], json_decode($childMessage->tool_calls, true));
        $this->assertSame([['result' => 'found']], json_decode($childMessage->tool_results, true));
    }

    public function test_it_lists_spaces_with_space_summary_keys(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $this->makeThread($space);

        SpaceActorState::query()
            ->where('space_id', $space->id)
            ->where('actorable_type', $user->getMorphClass())
            ->where('actorable_id', $user->id)
            ->update(['thread_id' => $thread->id]);

        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $this->getJson('/api/spaces')
            ->assertOk()
            ->assertJsonPath('data.0.id', $space->uuid)
            ->assertJsonPath('data.0.space.id', $space->uuid)
            ->assertJsonPath('data.0.space.active_thread_id', $thread->uuid)
            ->assertJsonMissingPath('data.0.channel');
    }

    public function test_it_returns_space_metadata_when_loading_a_thread(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $this->makeThread($space);

        $thread->messages()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'text' => 'Please confirm the visit window.',
            'meta' => [
                'source' => 'peer_message',
            ],
        ])->attachRelation($user, Post::RelationRoleSender);

        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $this->getJson(sprintf('/api/threads/%s', $thread->uuid))
            ->assertOk()
            ->assertJsonPath('space.id', $space->uuid)
            ->assertJsonPath('thread.space_id', $space->uuid)
            ->assertJsonPath('thread.id', $thread->uuid)
            ->assertJsonMissingPath('space.channel_id');
    }

    public function test_third_parties_can_define_thread_purpose_and_phase_through_the_api(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);

        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $response = $this->postJson('/api/nodes', [
            'type' => 'thread',
            'parent' => [
                'type' => 'space',
                'id' => $space->uuid,
            ],
            'attributes' => [
                'title' => 'External workflow',
                'purpose' => 'document_review',
                'phase' => 'awaiting_approval',
            ],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('threads', [
            'uuid' => $response->json('data.id'),
            'purpose' => 'document_review',
            'phase' => 'awaiting_approval',
        ]);
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

    protected function makeThread(Space $space): Thread
    {
        return $space->threads()->create([
            'purpose' => Thread::PurposeMain,
            'title' => 'Main Request Thread',
            'phase' => Thread::PhaseInitial,
            'status' => 'open',
        ]);
    }

    protected function addThreadActor(Thread $thread, string $role, string $actorableType, int $priority = 1): ThreadActor
    {
        return $thread->actors()->create([
            'actorable_type' => $actorableType,
            'actorable_id' => null,
            'role' => $role,
            'status' => ThreadActor::StatusActive,
            'priority' => $priority,
            'config' => null,
        ]);
    }

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Chat Tester',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'type' => User::TypeSubject,
            'status' => 'active',
        ]);
    }
}
