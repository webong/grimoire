<?php

namespace Webong\LaravelGouge\Tests\Feature;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Webong\LaravelGouge\Models\Server\AgentConversation;
use Webong\LaravelGouge\Models\Server\AgentConversationMessage;

class FormTurnsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_an_invocation_tree_without_a_post_identifier(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversationFor($user);
        $rootInvocationId = (string) fake()->uuid();
        $childInvocationId = (string) fake()->uuid();

        $root = $this->agentMessage(
            user: $user,
            conversation: $conversation,
            invocationId: $rootInvocationId,
            traceId: $rootInvocationId,
        );
        $child = $this->agentMessage(
            user: $user,
            conversation: $conversation,
            invocationId: $childInvocationId,
            traceId: $rootInvocationId,
            parentInvocationId: $rootInvocationId,
        );

        Sanctum::actingAs($user);

        $this->getJson("/api/form/{$rootInvocationId}/turns")
            ->assertOk()
            ->assertJsonPath('invocation_id', $rootInvocationId)
            ->assertJsonPath('trace_id', $rootInvocationId)
            ->assertJsonPath('data.0.agent_message_id', $root->id)
            ->assertJsonPath('data.0.invocable', null)
            ->assertJsonPath('data.0.children.0.agent_message_id', $child->id)
            ->assertJsonPath('data.0.children.0.parent_invocation_id', $rootInvocationId);
    }

    public function test_it_does_not_expose_another_users_invocation(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = $this->conversationFor($owner);
        $invocationId = (string) fake()->uuid();

        $this->agentMessage(
            user: $owner,
            conversation: $conversation,
            invocationId: $invocationId,
            traceId: $invocationId,
        );

        Sanctum::actingAs($intruder);

        $this->getJson("/api/form/{$invocationId}/turns")
            ->assertNotFound();
    }

    public function test_space_thread_and_post_resources_return_their_latest_invocation(): void
    {
        $user = User::factory()->create();
        $space = Space::factory()->create();
        SpaceActorState::query()->create([
            'space_id' => $space->id,
            'thread_id' => null,
            'actorable_type' => $user->getMorphClass(),
            'actorable_id' => $user->id,
            'status' => SpaceActorState::StatusActive,
        ]);
        $thread = $space->threads()->create([
            'title' => 'Form processing',
            'purpose' => Thread::PurposeMain,
            'phase' => Thread::PhaseInitial,
            'status' => 'open',
        ]);
        $post = $thread->posts()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'payload' => [
                'text' => 'Process this form.',
            ],
            'occurred_at' => now(),
        ]);
        $invocationId = (string) fake()->uuid();
        $conversation = $this->conversationFor($user);

        $this->agentMessage(
            user: $user,
            conversation: $conversation,
            invocationId: $invocationId,
            traceId: $invocationId,
            invocable: $post,
        );

        Sanctum::actingAs($user);

        $this->getJson("/api/spaces/{$space->uuid}")
            ->assertOk()
            ->assertJsonPath('data.invocation.invocation_id', $invocationId);

        $this->getJson("/api/threads/{$thread->uuid}")
            ->assertOk()
            ->assertJsonPath('thread.invocation.invocation_id', $invocationId);

        $this->getJson("/api/posts/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.invocation.invocation_id', $invocationId);

        $this->postJson('/api/graphql', [
            'query' => <<<'GRAPHQL'
                query GraphNodeInvocation($id: ID!) {
                    graphNode(type: POST, id: $id) {
                        invocation {
                            invocationId
                        }
                    }
                }
                GRAPHQL,
            'variables' => ['id' => $post->ulid],
        ])
            ->assertOk()
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.graphNode.invocation.invocationId', $invocationId);
    }

    protected function conversationFor(User $user): AgentConversation
    {
        return AgentConversation::query()->create([
            'id' => (string) fake()->uuid(),
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'Form execution',
        ]);
    }

    protected function agentMessage(
        User $user,
        AgentConversation $conversation,
        string $invocationId,
        string $traceId,
        ?string $parentInvocationId = null,
        ?Model $invocable = null,
    ): AgentConversationMessage {
        $message = new AgentConversationMessage;
        $message->forceFill([
            'id' => (string) fake()->uuid(),
            'conversation_id' => $conversation->id,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'agent' => 'form-agent',
            'role' => 'assistant',
            'invocation_id' => $invocationId,
            'trace_id' => $traceId,
            'parent_invocation_id' => $parentInvocationId,
            'invocable_type' => $invocable?->getMorphClass(),
            'invocable_id' => $invocable?->getKey(),
            'content' => 'Processed form node.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ])->save();

        return $message;
    }
}
