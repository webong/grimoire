<?php

namespace Webong\Gouge\Tests\Feature\A2a;

use App\Models\Server\Channel;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;
use Webong\Gouge\Ai\Support\A2a\A2aRegistry;
use Webong\Gouge\Ai\Tools\DelegateA2aTaskTool;
use Webong\Gouge\Ai\Tools\InvokeA2aAgentTool;
use Webong\Gouge\Ai\Tools\ListAvailableA2aAgentsTool;
use Webong\Gouge\Support\AgentChannels;

class OutboundA2aToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_out_untrusted_a2a_agents_from_the_available_list(): void
    {
        config()->set('a2a.outbound.trust', [
            'allow_http' => false,
            'allow_private_network' => false,
        ]);

        [$thread, $actor] = $this->conversationContext();
        $this->registerA2aAgentConnection($thread, 'trusted', [
            'label' => 'Trusted Agent',
            'endpoint_url' => 'https://agents.example/rpc',
            'allowed_methods' => ['message/send'],
        ]);
        $this->registerA2aAgentConnection($thread, 'blocked', [
            'label' => 'Blocked Agent',
            'endpoint_url' => 'http://127.0.0.1:4318/rpc',
            'allowed_methods' => ['message/send'],
        ]);

        $tool = new ListAvailableA2aAgentsTool($thread, $actor);
        $response = json_decode($tool->handle(new ToolRequest([])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($response['ok']);
        $this->assertTrue($response['enabled']);
        $this->assertSame(1, $response['count']);
        $this->assertSame(1, $response['filtered_out_count']);
        $this->assertSame('trusted', data_get($response, 'agents.0.id'));
    }

    public function test_it_denies_invocation_for_an_untrusted_a2a_agent_endpoint(): void
    {
        config()->set('a2a.outbound.trust', [
            'allow_http' => false,
            'allow_private_network' => false,
        ]);

        Http::preventStrayRequests();

        [$thread, $actor] = $this->conversationContext();
        $this->registerA2aAgentConnection($thread, 'blocked', [
            'label' => 'Blocked Agent',
            'endpoint_url' => 'http://127.0.0.1:4318/rpc',
            'allowed_methods' => ['message/send'],
        ]);
        $tool = new InvokeA2aAgentTool($thread, $actor);
        $response = json_decode($tool->handle(new ToolRequest([
            'agent' => 'blocked',
            'method' => 'message/send',
            'params' => [
                'message' => [
                    'role' => 'user',
                    'parts' => [['text' => 'Hello']],
                ],
            ],
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($response['ok']);
        $this->assertFalse($response['allowed']);
        $this->assertSame('Plain HTTP URLs are not allowed by policy.', $response['error']);

        Http::assertNothingSent();

        $event = ThreadEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('a2a.outbound.failure', $event->event_type);
        $this->assertSame('a2a_endpoint_denied', data_get($event->payload, 'error_code'));
    }

    public function test_it_denies_delegation_for_an_untrusted_a2a_agent_endpoint(): void
    {
        config()->set('a2a.outbound.trust', [
            'allow_http' => false,
            'allow_private_network' => false,
        ]);

        Http::preventStrayRequests();

        [$thread, $actor] = $this->conversationContext();
        $this->registerA2aAgentConnection($thread, 'blocked', [
            'label' => 'Blocked Agent',
            'endpoint_url' => 'http://127.0.0.1:4318/rpc',
            'allowed_methods' => ['message/send', 'tasks/get'],
        ]);
        $tool = new DelegateA2aTaskTool($thread, $actor);
        $response = json_decode($tool->handle(new ToolRequest([
            'agent' => 'blocked',
            'message' => 'Review this diff.',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($response['ok']);
        $this->assertSame('config', $response['stage']);
        $this->assertSame('Plain HTTP URLs are not allowed by policy.', $response['error']);

        Http::assertNothingSent();
        $this->assertSame(0, ThreadEvent::query()->where('event_key', 'agent_task')->count());
    }

    public function test_it_resolves_registered_outbound_a2a_agents_from_thread_context(): void
    {
        [$thread, $actor] = $this->conversationContext();
        $this->registerA2aAgentConnection($thread, 'planner', [
            'label' => 'Planning Agent',
            'endpoint_url' => 'https://agents.example/rpc',
            'auth_type' => 'bearer',
            'credentials' => [
                'token' => 'planner-token',
                'headers' => [
                    'X-A2A-Agent' => 'planner',
                ],
            ],
            'allowed_methods' => ['message/send', 'tasks/get'],
        ]);

        $registry = app(A2aRegistry::class);
        $agents = $registry->agents($thread, $actor);

        $this->assertCount(1, $agents);
        $this->assertSame('planner', data_get($agents, '0.id'));
        $this->assertSame('https://agents.example/rpc', data_get($agents, '0.endpoint'));
        $this->assertSame('bearer', data_get($agents, '0.auth_type'));
        $this->assertSame('planner-token', data_get($agents, '0.token'));
        $this->assertSame('planner', data_get($agents, '0.headers.X-A2A-Agent'));
    }

    /**
     * @return array{0: Thread, 1: User}
     */
    protected function conversationContext(): array
    {
        $space = Space::factory()->create();
        $actor = User::factory()->create();
        $thread = Thread::factory()->create([
            'threadable_type' => $space->getMorphClass(),
            'threadable_id' => $space->getKey(),
            'purpose' => 'execution',
            'status' => 'open',
        ]);

        return [$thread, $actor];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function registerA2aAgentConnection(Thread $thread, string $agentId, array $config = []): void
    {
        $channel = Channel::factory()->create([
            'driver' => AgentChannels::A2a,
            'server' => $agentId,
            'name' => $config['label'] ?? ucfirst($agentId).' A2A',
            'label' => $config['label'] ?? ucfirst($agentId).' A2A',
            'enabled' => true,
            'status' => Channel::StatusActive,
            'transport' => null,
            'endpoint_url' => null,
            'auth_type' => null,
            'credentials' => [],
            'config' => [],
            'meta' => [],
        ]);

        app(ChannelLinkRepository::class)->create($channel, $thread, $thread, [
            'status' => Channel::StatusActive,
            'direction' => Channel::DirectionOutbound,
            'config' => $config,
            'data' => [
                'agent_id' => $agentId,
            ],
            'meta' => [],
        ]);
    }
}
