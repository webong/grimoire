<?php

namespace Webong\Gouge\Tests\Feature;

use App\Coordination\WorkExchange\V1\WorkRequest;
use App\Features\Actions\Chat\Protocols\WorkExchangeProtocol;
use App\Jobs\DeliverOutboxMessage;
use App\Models\Server\Inbox;
use App\Models\Server\Outbox;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use App\TokenAbility;
use Figurate\Auth\Support\RobotUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Webong\Gouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\Gouge\Coordination\GougeBridge;
use Webong\Gouge\Features\Actions\Chat\EnqueueThreadPromptOutbox;
use Webong\Gouge\Support\AgentActors;

class AgentPromptOutboxDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enqueues_an_agent_prompt_outbox_dispatch_from_completion_notifications(): void
    {
        Queue::fake();

        $sender = User::factory()->create();
        $robot = User::factory()->create([
            'type' => RobotUsers::Robot,
        ]);
        $space = Space::factory()->create();
        $thread = $space->threads()->create([
            'purpose' => Thread::PurposeMain,
            'title' => 'Prompt Dispatch Thread',
            'phase' => 'execution',
            'status' => 'open',
        ]);

        SpaceActorState::query()->create([
            'space_id' => $space->id,
            'thread_id' => null,
            'actorable_type' => $sender->getMorphClass(),
            'actorable_id' => $sender->id,
            'status' => SpaceActorState::StatusActive,
        ]);
        SpaceActorState::query()->create([
            'space_id' => $space->id,
            'thread_id' => null,
            'actorable_type' => $robot->getMorphClass(),
            'actorable_id' => $robot->id,
            'status' => SpaceActorState::StatusActive,
        ]);

        $thread->actors()->create([
            'actorable_type' => $sender->getMorphClass(),
            'actorable_id' => $sender->id,
            'role' => ThreadActor::RoleMember,
            'status' => ThreadActor::StatusActive,
            'priority' => 1,
            'config' => null,
        ]);
        $thread->actors()->create([
            'actorable_type' => $robot->getMorphClass(),
            'actorable_id' => $robot->id,
            'role' => ThreadActor::RoleMember,
            'status' => ThreadActor::StatusActive,
            'priority' => 2,
            'config' => null,
        ]);
        $presenter = $thread->actors()->create([
            'actorable_type' => AgentActors::Coordinator,
            'actorable_id' => null,
            'role' => AgentActors::RolePresenter,
            'status' => ThreadActor::StatusActive,
            'priority' => 3,
            'config' => null,
        ]);

        Sanctum::actingAs($sender, [TokenAbility::Compose->value]);

        $response = $this->postJson('/api/form', [
            'body' => [
                'type' => 'post',
                'parent' => [
                    'type' => 'thread',
                    'id' => $thread->uuid,
                ],
                'attributes' => [
                    'post_type' => Post::TypeMessage,
                    'text' => 'Compile the latest update.',
                    'conversation_persistence' => ConversationPersistenceResolver::ThreadCompletion,
                ],
            ],
        ])->assertAccepted();

        $post = Post::query()
            ->where('ulid', (string) $response->json('post_id'))
            ->firstOrFail();

        $outbox = Outbox::query()
            ->where('thread_id', $thread->id)
            ->where('post_id', $post->id)
            ->where('protocol', WorkExchangeProtocol::Key)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame(Outbox::DirectionOutbound, $outbox->direction);
        $this->assertSame(GougeBridge::Key, $outbox->provider);
        $this->assertSame(AgentActors::Coordinator, $outbox->target);
        $this->assertSame(WorkRequest::Type, data_get($outbox->payload, 'type'));
        $this->assertSame(
            ConversationPersistenceResolver::ThreadCompletion,
            data_get($outbox->payload, 'action.input.conversation_persistence'),
        );
        $this->assertSame($robot->id, data_get($outbox->payload, 'action.input.recipient_user_id'));
        $this->assertSame((string) $presenter->id, data_get($outbox->payload, 'participant.id'));

        Queue::assertPushed(DeliverOutboxMessage::class, function (DeliverOutboxMessage $job) use ($outbox): bool {
            return $job->outboxId === $outbox->id;
        });

        $event = ThreadEvent::query()
            ->where('thread_id', $thread->id)
            ->where('post_id', $post->id)
            ->where('event_type', 'orchestration.notification.completion_requested')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('notification.space.completion', $event->operation);
        $this->assertSame('outbox_enqueued', data_get($event->payload, 'reason'));
        $this->assertSame($outbox->id, data_get($event->payload, 'outbox_id'));
        $this->assertSame(WorkExchangeProtocol::Key, data_get($event->payload, 'outbox_protocol'));

        $this->assertDatabaseMissing('inboxes', [
            'user_id' => $robot->id,
            'inboxable_id' => $post->id,
            'kind' => Inbox::KindThreadMessage,
        ]);
    }

    public function test_it_is_idempotent_when_enqueuing_the_same_prompt_dispatch_twice(): void
    {
        Queue::fake();

        $recipient = User::factory()->create([
            'type' => RobotUsers::Robot,
        ]);
        $sender = User::factory()->create();
        $space = Space::factory()->create();
        $thread = $space->threads()->create([
            'purpose' => Thread::PurposeMain,
            'title' => 'Prompt Idempotency Thread',
            'phase' => 'execution',
            'status' => 'open',
        ]);
        $post = $thread->posts()->create([
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'data' => [
                'text' => 'Generate a summary.',
                'message_type' => 'text',
            ],
            'meta' => [
                'source' => 'peer_message',
            ],
        ]);
        $post->attachRelation($sender, Post::RelationRoleSender);
        $presenter = $thread->actors()->create([
            'actorable_type' => AgentActors::Coordinator,
            'actorable_id' => null,
            'role' => AgentActors::RolePresenter,
            'status' => ThreadActor::StatusActive,
            'priority' => 1,
            'config' => null,
        ]);

        $action = app(EnqueueThreadPromptOutbox::class);

        $first = $action->execute(
            thread: $thread,
            post: $post,
            recipient: $recipient,
            threadActor: $presenter,
            conversationPersistenceMode: ConversationPersistenceResolver::ThreadContinuation,
        );
        $second = $action->execute(
            thread: $thread,
            post: $post,
            recipient: $recipient,
            threadActor: $presenter,
            conversationPersistenceMode: ConversationPersistenceResolver::ThreadContinuation,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('outboxes', 1);

        Queue::assertPushed(DeliverOutboxMessage::class, 1);
    }
}
