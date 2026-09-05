<?php

namespace Webong\LaravelGouge\Tests\Feature\Mcp;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\SpaceRelation;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadRelation;
use App\Models\Server\User;
use Database\Factories\SpaceFactory;
use Figurate\Auth\Support\RobotUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;
use Tests\TestCase;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Prompts\PlanSpaceWorkPrompt;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Resources\ComposeServerGuideResource;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Servers\ComposeServer;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\AssignThreadActorTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\CreateGraphEdgeTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\CreatePostTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\CreateThreadTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\ListSpacesTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\QueryGraphEdgesTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\ReadThreadTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\SearchConversationContextTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\TransferThreadSessionTool;
use Webong\LaravelGouge\Models\Server\AgentConversation;
use Webong\LaravelGouge\Models\Server\ThreadActorSession;
use Webong\LaravelGouge\Support\AgentActors;

class ComposeMcpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_registered_as_a_named_hosted_server(): void
    {
        $route = app(Registrar::class)->getWebServer('api/mcp/compose');

        $this->assertNotNull($route);
        $this->assertSame('api/mcp/compose', $route->uri());
    }

    public function test_it_lists_only_accessible_spaces(): void
    {
        $user = $this->makeUser(RobotUsers::Robot);
        $visibleSpace = $this->accessibleSpace($user);
        SpaceFactory::new()->create();

        $response = ComposeServer::actingAs($user)->tool(ListSpacesTool::class, [
            'limit' => 10,
        ]);

        $response->assertOk()->assertSee($visibleSpace->uuid);
        $response->assertDontSee('"count":0');
    }

    public function test_it_reads_a_thread_with_messages_posts_and_actors(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $space->threads()->create([
            'title' => 'Repair scope',
            'purpose' => 'planning',
            'phase' => 'scope_planning',
            'status' => 'open',
        ]);
        $thread->actors()->create([
            'actorable_type' => $user->getMorphClass(),
            'actorable_id' => $user->getKey(),
            'role' => ThreadActor::RoleMember,
            'status' => ThreadActor::StatusActive,
            'priority' => 1,
            'config' => null,
        ]);
        $message = Post::query()->create([
            'postable_type' => $thread->getMorphClass(),
            'postable_id' => $thread->id,
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'text' => 'Need a quick check on the kitchen sink leak.',
            'meta' => ['source' => 'test'],
        ]);
        $message->attachRelation($user, Post::RelationRoleSender);
        $thread->posts()->create([
            'type' => 'summary.snapshot',
            'status' => 'draft',
            'payload' => ['title' => 'Scope summary'],
            'meta' => ['source' => 'test'],
            'occurred_at' => now(),
        ]);

        $response = ComposeServer::actingAs($user)->tool(ReadThreadTool::class, [
            'thread_id' => $thread->uuid,
        ]);

        $response->assertOk()
            ->assertSee($thread->uuid)
            ->assertSee('kitchen sink leak')
            ->assertSee('summary.snapshot');
    }

    public function test_it_creates_threads_and_posts_via_mcp_tools(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);

        $createThread = ComposeServer::actingAs($user)->tool(CreateThreadTool::class, [
            'space_id' => $space->uuid,
            'title' => 'Source replacement',
            'purpose' => 'asset_update',
            'phase' => 'ready_for_review',
        ]);

        $createThread->assertOk()->assertSee('Source replacement');

        $thread = Thread::query()->where('title', 'Source replacement')->firstOrFail();
        $this->assertSame('asset_update', $thread->purpose);
        $this->assertSame('ready_for_review', $thread->phase);

        $createPost = ComposeServer::actingAs($user)->tool(CreatePostTool::class, [
            'target_type' => 'thread',
            'target_id' => $thread->uuid,
            'type' => 'note.created',
            'title' => 'Inspection note',
            'body' => 'Technician should bring replacement hose.',
        ]);

        $createPost->assertOk()
            ->assertSee('note.created')
            ->assertSee('Inspection note');

        $this->assertDatabaseHas('posts', [
            'postable_type' => $thread->getMorphClass(),
            'postable_id' => $thread->id,
            'type' => 'note.created',
        ]);
    }

    public function test_it_creates_graph_edges_via_mcp_tools(): void
    {
        $user = $this->makeUser();
        $sourceSpace = $this->accessibleSpace($user);
        $targetSpace = $this->accessibleSpace($user);

        $response = ComposeServer::actingAs($user)->tool(CreateGraphEdgeTool::class, [
            'source_type' => 'space',
            'source_id' => $sourceSpace->uuid,
            'target_type' => 'space',
            'target_id' => $targetSpace->uuid,
            'edge_type' => SpaceRelation::TypeDependsOn,
            'purpose' => 'Need target space as an upstream dependency.',
        ]);

        $response->assertOk()
            ->assertSee(SpaceRelation::TypeDependsOn)
            ->assertSee($sourceSpace->uuid)
            ->assertSee($targetSpace->uuid);

        $this->assertDatabaseHas('space_relations', [
            'space_id' => $sourceSpace->id,
            'relationable_type' => $targetSpace->getMorphClass(),
            'relationable_id' => $targetSpace->id,
            'type' => SpaceRelation::TypeDependsOn,
        ]);
    }

    public function test_it_creates_post_graph_edges_via_mcp_tools(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $space->threads()->create([
            'title' => 'Source thread',
            'purpose' => 'planning',
            'phase' => 'scope_planning',
            'status' => 'open',
        ]);
        $post = Post::query()->create([
            'postable_type' => $thread->getMorphClass(),
            'postable_id' => $thread->id,
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'text' => 'Attach this message to the related thread.',
            'meta' => ['source' => 'test'],
        ]);
        $post->attachRelation($user, Post::RelationRoleSender);
        $targetThread = $space->threads()->create([
            'title' => 'Target thread',
            'purpose' => 'execution',
            'phase' => 'order_kickoff',
            'status' => 'open',
        ]);

        $response = ComposeServer::actingAs($user)->tool(CreateGraphEdgeTool::class, [
            'source_type' => 'post',
            'source_id' => $post->ulid,
            'target_type' => 'thread',
            'target_id' => $targetThread->uuid,
            'edge_type' => ThreadRelation::TypeReferences,
            'purpose' => 'This purpose is not stored for post-backed edges.',
        ]);

        $response->assertOk()
            ->assertSee(ThreadRelation::TypeReferences)
            ->assertSee($post->ulid)
            ->assertSee($targetThread->uuid);

        $this->assertDatabaseHas('post_relations', [
            'post_id' => $post->id,
            'relationable_type' => $targetThread->getMorphClass(),
            'relationable_id' => $targetThread->id,
            'role' => ThreadRelation::TypeReferences,
        ]);
    }

    public function test_it_searches_thread_context(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $space->threads()->create([
            'title' => 'Drain issue',
            'purpose' => 'support',
            'phase' => 'support_open',
            'status' => 'open',
        ]);
        $message = Post::query()->create([
            'postable_type' => $thread->getMorphClass(),
            'postable_id' => $thread->id,
            'type' => Post::TypeMessage,
            'status' => Post::StatusActive,
            'text' => 'The basement drain smells bad after the rain.',
            'meta' => ['source' => 'test'],
        ]);
        $message->attachRelation($user, Post::RelationRoleSender);
        Post::query()->create([
            'postable_type' => $thread->getMorphClass(),
            'postable_id' => $thread->id,
            'type' => 'summary.snapshot',
            'status' => 'draft',
            'payload' => ['body' => 'Drain odor likely from trapped debris.'],
            'meta' => ['source' => 'test'],
            'occurred_at' => now(),
        ]);

        $response = ComposeServer::actingAs($user)->tool(SearchConversationContextTool::class, [
            'thread_id' => $thread->uuid,
            'query' => 'drain',
        ]);

        $response->assertOk()
            ->assertSee('basement drain smells bad')
            ->assertSee('Drain odor likely from trapped debris');
    }

    public function test_it_queries_graph_edges_with_depth(): void
    {
        $user = $this->makeUser();
        $sourceSpace = $this->accessibleSpace($user);
        $dependencySpace = $this->accessibleSpace($user);
        $knowledgeSpace = $this->accessibleSpace($user);
        $sourceThread = $sourceSpace->threads()->create([
            'title' => 'Source thread',
            'purpose' => 'planning',
            'phase' => 'scope_planning',
            'status' => 'open',
        ]);
        $dependencyThread = $dependencySpace->threads()->create([
            'title' => 'Dependency thread',
            'purpose' => 'execution',
            'phase' => 'order_kickoff',
            'status' => 'open',
        ]);
        $knowledgeThread = $knowledgeSpace->threads()->create([
            'title' => 'Knowledge thread',
            'purpose' => 'support',
            'phase' => 'support_open',
            'status' => 'open',
        ]);

        $sourceSpace->attachRelation($dependencySpace, SpaceRelation::TypeDependsOn, 'Depends on related work context');
        $dependencySpace->attachRelation($knowledgeSpace, SpaceRelation::TypeReferences, 'Needs supporting knowledge context');
        $dependencySpace->attachRelation($dependencyThread, SpaceRelation::TypeReferences, 'Tracks execution work');
        $dependencyThread->attachRelation($knowledgeThread, ThreadRelation::TypeReferences, 'See related thread');

        $knowledgePost = Post::query()->create([
            'postable_type' => $knowledgeThread->getMorphClass(),
            'postable_id' => $knowledgeThread->id,
            'type' => 'summary.snapshot',
            'status' => Post::StatusActive,
            'payload' => ['body' => 'Supporting knowledge artifact'],
            'meta' => ['source' => 'test'],
            'occurred_at' => now(),
        ]);
        $knowledgePost->attachRelation($user, Post::RelationRoleSender);
        $knowledgeThread->attachRelation($knowledgePost, ThreadRelation::TypeDerivedFrom, 'Derived artifact');

        $response = ComposeServer::actingAs($user)->tool(QueryGraphEdgesTool::class, [
            'node_type' => 'space',
            'node_id' => $sourceSpace->uuid,
            'direction' => 'outgoing',
            'depth' => 4,
        ]);

        $response->assertOk()
            ->assertSee($sourceSpace->uuid)
            ->assertSee($dependencySpace->uuid)
            ->assertSee($knowledgeSpace->uuid)
            ->assertSee(ThreadRelation::TypeReferences)
            ->assertSee($knowledgePost->ulid)
            ->assertSee('edge_count');

        $this->assertEqualsCanonicalizing(
            [$sourceThread->id, $dependencyThread->id, $knowledgeThread->id],
            $sourceSpace->conversationThreadIds()->all(),
        );
    }

    public function test_it_exposes_a_guide_resource_and_channel_planning_prompt(): void
    {
        $user = $this->makeUser();
        $space = $this->accessibleSpace($user);

        ComposeServer::actingAs($user)
            ->resource(ComposeServerGuideResource::class)
            ->assertOk()
            ->assertSee('Compose MCP Guide')
            ->assertSee('create_thread');

        ComposeServer::actingAs($user)
            ->prompt(PlanSpaceWorkPrompt::class, [
                'space_id' => $space->uuid,
                'objective' => 'Prepare the next repair workflow.',
            ])
            ->assertOk()
            ->assertSee($space->uuid)
            ->assertSee('Prepare the next repair workflow');
    }

    public function test_it_assigns_thread_actors_and_transfers_thread_sessions(): void
    {
        $user = $this->makeUser();
        $targetUser = $this->makeUser();
        $space = $this->accessibleSpace($user);
        $thread = $space->threads()->create([
            'title' => 'Assigned work',
            'purpose' => 'execution',
            'phase' => 'order_kickoff',
            'status' => 'open',
        ]);

        $assignResponse = ComposeServer::actingAs($user)->tool(AssignThreadActorTool::class, [
            'thread_id' => $thread->uuid,
            'actor_type' => 'named',
            'actor_key' => AgentActors::Coordinator,
            'role' => AgentActors::RolePresenter,
            'status' => ThreadActor::StatusActive,
        ]);

        $assignResponse->assertOk()->assertSee(AgentActors::Coordinator);

        $threadActor = ThreadActor::query()
            ->where('thread_id', $thread->id)
            ->where('actorable_type', AgentActors::Coordinator)
            ->where('role', AgentActors::RolePresenter)
            ->firstOrFail();

        $conversation = new AgentConversation;
        $conversation->forceFill([
            'id' => (string) Str::uuid(),
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'Transfer source session',
        ])->save();

        $fromSession = ThreadActorSession::query()->create([
            'thread_id' => $thread->id,
            'thread_actor_id' => $threadActor->id,
            'user_id' => $user->id,
            'provider' => 'openai',
            'model' => 'gpt-test',
            'conversation_id' => $conversation->id,
            'state' => null,
            'last_used_at' => now(),
        ]);

        $transferResponse = ComposeServer::actingAs($user)->tool(TransferThreadSessionTool::class, [
            'thread_id' => $thread->uuid,
            'from_user_id' => $user->id,
            'to_user_id' => $targetUser->id,
            'thread_actor_id' => $threadActor->id,
        ]);

        $transferResponse->assertOk()
            ->assertSee((string) $fromSession->id)
            ->assertSee($conversation->id);

        $this->assertDatabaseHas('thread_actor_sessions', [
            'thread_id' => $thread->id,
            'thread_actor_id' => $threadActor->id,
            'user_id' => $targetUser->id,
            'conversation_id' => $conversation->id,
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

    protected function makeUser(string $type = User::TypeSubject): User
    {
        return User::query()->create([
            'name' => 'MCP Tester',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'type' => $type,
            'status' => 'active',
        ]);
    }
}
