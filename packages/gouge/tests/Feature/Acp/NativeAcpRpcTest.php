<?php

namespace Webong\Gouge\Tests\Feature\Acp;

use App\Models\Server\Space;
use App\Models\Server\SpaceActorState;
use App\Models\Server\Thread;
use App\Models\Server\User;
use Figurate\Auth\Support\RobotUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;
use Webong\Gouge\Contracts\AgentRuntime;

class NativeAcpRpcTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_negotiates_acp_version_and_capabilities(): void
    {
        $this->authenticate();

        $this->rpc('initialize', [
            'protocolVersion' => 1,
            'clientCapabilities' => [],
            'clientInfo' => [
                'name' => 'test-client',
                'version' => '1.0.0',
            ],
        ])->assertOk()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('id', 1)
            ->assertJsonPath('result.protocolVersion', 1)
            ->assertJsonPath('result.agentCapabilities.loadSession', true)
            ->assertJsonPath('result.agentCapabilities.sessionCapabilities.list', []);
    }

    public function test_it_creates_lists_and_loads_native_acp_sessions(): void
    {
        $user = $this->authenticate();
        $space = $this->accessibleSpace($user);

        $create = $this->rpc('session/new', [
            'cwd' => '/workspace/figurate',
            'mcpServers' => [],
            '_meta' => [
                'figurate' => [
                    'spaceId' => $space->uuid,
                    'title' => 'Native ACP Session',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('result._meta.figurate.spaceId', $space->uuid);

        $sessionId = (string) $create->json('result.sessionId');

        $this->assertDatabaseHas('threads', [
            'uuid' => $sessionId,
            'title' => 'Native ACP Session',
            'purpose' => Thread::PurposeMain,
            'phase' => Thread::PhaseInitial,
        ]);

        $this->rpc('session/list')
            ->assertOk()
            ->assertJsonPath('result.sessions.0.sessionId', $sessionId)
            ->assertJsonPath('result.sessions.0._meta.figurate.spaceId', $space->uuid);

        $this->rpc('session/load', [
            'sessionId' => $sessionId,
            'cwd' => '/workspace/figurate',
            'mcpServers' => [],
        ])->assertOk()
            ->assertJsonPath('id', 1)
            ->assertJsonPath('result', null);
    }

    public function test_it_bootstraps_a_figurate_space_for_a_standard_session_new_request(): void
    {
        $user = $this->authenticate();

        $create = $this->rpc('session/new', [
            'cwd' => '/workspace/new-project',
            'mcpServers' => [],
        ])->assertOk();

        $spaceId = (string) $create->json('result._meta.figurate.spaceId');

        $this->assertDatabaseHas('spaces', ['uuid' => $spaceId]);
        $this->assertDatabaseHas('actor_states', [
            'actorable_type' => $user->getMorphClass(),
            'actorable_id' => $user->id,
            'status' => SpaceActorState::StatusActive,
        ]);
    }

    public function test_it_prompts_tracks_and_cancels_a_native_acp_task(): void
    {
        $this->mock(AgentRuntime::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitTurn')->once();
        });

        $user = $this->authenticate();
        $space = $this->accessibleSpace($user);
        $sessionId = (string) $this->rpc('session/new', [
            'cwd' => '/workspace/figurate',
            'mcpServers' => [],
            '_meta' => ['figurate' => ['spaceId' => $space->uuid]],
        ])->json('result.sessionId');

        $prompt = $this->rpc('session/prompt', [
            'sessionId' => $sessionId,
            'prompt' => [
                ['type' => 'text', 'text' => 'Inspect the remote device.'],
            ],
        ])->assertOk()
            ->assertJsonPath('result.stopReason', 'end_turn')
            ->assertJsonPath('result._meta.figurate.asynchronous', true)
            ->assertJsonPath('result._meta.figurate.task.state', 'submitted');

        $taskId = (string) $prompt->json('result._meta.figurate.task.id');

        $this->rpc('tasks/get', ['taskId' => $taskId])
            ->assertOk()
            ->assertJsonPath('result.id', $taskId)
            ->assertJsonPath('result.state', 'submitted');

        $this->postJson('/api/acp/rpc', [
            'jsonrpc' => '2.0',
            'method' => 'session/cancel',
            'params' => ['sessionId' => $sessionId],
        ])->assertNoContent();

        $this->rpc('tasks/get', ['taskId' => $taskId])
            ->assertOk()
            ->assertJsonPath('result.state', 'canceled');
    }

    public function test_it_returns_json_rpc_errors_for_invalid_requests_and_unknown_methods(): void
    {
        $this->authenticate();

        $this->postJson('/api/acp/rpc', [
            'jsonrpc' => '1.0',
            'id' => 8,
            'method' => 'initialize',
        ])->assertOk()
            ->assertJsonPath('error.code', -32600);

        $this->rpc('unknown/method')
            ->assertOk()
            ->assertJsonPath('error.code', -32601);

        $this->rpc('session/new', [
            'cwd' => 'relative/path',
            'mcpServers' => [],
        ])->assertOk()
            ->assertJsonPath('error.code', -32602);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/api/acp/rpc', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);
    }

    protected function authenticate(): User
    {
        $user = User::query()->create([
            'name' => 'Native ACP Tester',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'type' => RobotUsers::Robot,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user, ['acp:use']);

        return $user;
    }

    protected function accessibleSpace(User $user): Space
    {
        $space = Space::factory()->create();

        SpaceActorState::query()->create([
            'space_id' => $space->id,
            'thread_id' => null,
            'actorable_type' => $user->getMorphClass(),
            'actorable_id' => $user->id,
            'status' => SpaceActorState::StatusActive,
        ]);

        return $space;
    }
}
