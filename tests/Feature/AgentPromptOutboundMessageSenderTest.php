<?php

namespace Webong\LaravelGouge\Tests\Feature;

use App\Models\Server\Outbox;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Contracts\AgentRuntime;
use Webong\LaravelGouge\Features\Actions\Chat\AgentPromptOutboundMessageSender;
use Webong\LaravelGouge\Features\Actions\Chat\Protocols\AgentPromptProtocol;
use Webong\LaravelGouge\Runtime\AgentRuntimeCorrelation;
use Webong\LaravelGouge\Runtime\AgentTurn;
use Webong\LaravelGouge\Support\AgentActors;
use Webong\LaravelGouge\Support\AgentUsers;

class AgentPromptOutboundMessageSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_submits_a_runtime_neutral_turn_from_an_agent_prompt_outbox_record(): void
    {
        $recipient = User::factory()->create([
            'type' => AgentUsers::Robot,
        ]);
        $sender = User::factory()->create();
        $space = Space::factory()->create();
        $thread = $space->threads()->create([
            'purpose' => Thread::PurposeMain,
            'title' => 'Prompt Sender Thread',
            'phase' => 'execution',
            'status' => 'open',
        ]);
        $post = $thread->posts()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'data' => [
                'text' => 'Prepare a concise answer.',
                'message_type' => 'text',
            ],
            'senderable_type' => $sender->getMorphClass(),
            'senderable_id' => $sender->id,
            'meta' => [
                'source' => 'peer_message',
            ],
        ]);
        $presenter = $thread->actors()->create([
            'actorable_type' => AgentActors::Coordinator,
            'actorable_id' => null,
            'role' => AgentActors::RolePresenter,
            'status' => ThreadActor::StatusActive,
            'priority' => 1,
            'config' => null,
        ]);
        $outbox = Outbox::query()->create([
            'thread_id' => $thread->id,
            'post_id' => $post->id,
            'direction' => Outbox::DirectionOutbound,
            'protocol' => AgentPromptProtocol::Key,
            'provider' => 'laravel-ai',
            'target' => AgentActors::Coordinator,
            'status' => Outbox::StatusPending,
            'attempts' => 0,
            'available_at' => now(),
            'idempotency_key' => 'prompt:test',
            'payload' => [
                'dispatch' => [
                    'recipient_user_id' => $recipient->id,
                    'thread_actor_id' => $presenter->id,
                    'broadcast_space_id' => "threads.{$thread->uuid}",
                    'conversation_persistence' => ConversationPersistenceResolver::ThreadCompletion,
                ],
            ],
        ]);

        $runtime = Mockery::mock(AgentRuntime::class);
        $runtime->shouldReceive('correlationFor')
            ->once()
            ->withArgs(fn (AgentTurn $turn): bool => $turn->postId === $post->id)
            ->andReturn(new AgentRuntimeCorrelation(
                id: 'correlation-1',
                runtime: 'laravel-ai',
                threadId: $thread->id,
                threadUuid: $thread->uuid,
                postId: $post->id,
                postUlid: $post->ulid,
                threadActorId: $presenter->id,
                actorKey: AgentActors::Coordinator,
            ));
        $runtime->shouldReceive('submitTurn')
            ->once()
            ->withArgs(function (AgentTurn $turn) use ($thread, $post, $recipient, $presenter): bool {
                return $turn->threadId === $thread->id
                    && $turn->postId === $post->id
                    && $turn->userId === $recipient->id
                    && $turn->threadActorId === $presenter->id
                    && $turn->broadcastChannel === "threads.{$thread->uuid}"
                    && $turn->conversationPersistenceMode === ConversationPersistenceResolver::ThreadCompletion;
            });

        $result = (new AgentPromptOutboundMessageSender($runtime))->send($outbox);

        $this->assertTrue((bool) data_get($result, 'ok'));
        $this->assertSame(AgentPromptProtocol::Key, data_get($result, 'protocol'));
        $this->assertSame('queued_for_agent', data_get($result, 'delivery'));
        $this->assertSame($presenter->id, data_get($result, 'thread_actor_id'));
        $this->assertSame('correlation-1', data_get($result, 'correlation.id'));
        $this->assertSame(
            ConversationPersistenceResolver::ThreadCompletion,
            data_get($result, 'conversation_persistence'),
        );
    }
}
