<?php

namespace Webong\Gouge\Tests\Feature\Acp;

use App\Models\Server\Channel;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;
use Webong\Gouge\Ai\Tools\DelegateAcpTaskTool;
use Webong\Gouge\Ai\Tools\InvokeAcpAgentTool;
use Webong\Gouge\Ai\Tools\ListAvailableAcpAgentsTool;
use Webong\Gouge\Support\AgentChannels;
use Webong\Gouge\Support\AgentEvents;
use Webong\Gouge\Support\Orchestrate\TaskRecord;

class OutboundAcpToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_available_outbound_acp_agents(): void
    {
        [$thread, $actor] = $this->conversationContext();
        $this->registerAcpAgentConnection($thread, 'gemini', [
            'label' => 'Gemini CLI Bridge',
            'transport' => 'http',
            'endpoint_url' => 'https://acp.example/rpc',
            'auth_type' => 'bearer',
            'credentials' => [
                'token' => 'secret-token',
                'headers' => [
                    'X-ACP-Agent' => 'gemini',
                ],
            ],
            'allowed_methods' => [
                'initialize',
                'session/new',
                'session/prompt',
            ],
        ]);

        $tool = new ListAvailableAcpAgentsTool($thread, $actor);
        $response = json_decode($tool->handle(new ToolRequest([
            'include_headers' => true,
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($response['ok']);
        $this->assertTrue($response['enabled']);
        $this->assertSame(1, $response['count']);
        $this->assertSame('gemini', data_get($response, 'agents.0.id'));
        $this->assertSame('jsonrpc-http', data_get($response, 'agents.0.transport'));
        $this->assertTrue(data_get($response, 'agents.0.has_token'));
        $this->assertSame('gemini', data_get($response, 'agents.0.headers.X-ACP-Agent'));
    }

    public function test_it_invokes_an_outbound_acp_agent_and_records_an_event(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acp.example/rpc' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 'rpc-1',
                'result' => [
                    'session' => [
                        'id' => 'remote-session-1',
                    ],
                ],
            ]),
        ]);

        [$thread, $actor] = $this->conversationContext();
        $this->registerAcpAgentConnection($thread, 'opencode', [
            'label' => 'OpenCode Bridge',
            'transport' => 'http',
            'endpoint_url' => 'https://acp.example/rpc',
            'allowed_methods' => [
                'session/new',
            ],
        ]);
        $tool = new InvokeAcpAgentTool($thread, $actor);
        $response = json_decode($tool->handle(new ToolRequest([
            'agent' => 'opencode',
            'method' => 'session/new',
            'params' => [
                'title' => 'Remote coding session',
            ],
            'rpc_id' => 'rpc-1',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($response['ok']);
        $this->assertTrue($response['allowed']);
        $this->assertSame('remote-session-1', data_get($response, 'result.session.id'));

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://acp.example/rpc'
                && $request['method'] === 'session/new'
                && data_get($request['params'], 'title') === 'Remote coding session';
        });

        $event = ThreadEvent::query()->latest('id')->firstOrFail();
        $this->assertSame(AgentEvents::Acp, $event->kind);
        $this->assertSame('session/new', $event->operation);
        $this->assertSame('acp.outbound.success', $event->event_type);
    }

    public function test_it_invokes_an_outbound_acp_gateway_agent(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:4319/rpc' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 'rpc-gateway-1',
                'result' => [
                    'session' => [
                        'id' => 'gateway-session-1',
                    ],
                ],
            ]),
        ]);

        [$thread, $actor] = $this->conversationContext();
        $this->registerAcpAgentConnection($thread, 'opencode', [
            'label' => 'OpenCode Gateway',
            'transport' => 'http',
            'endpoint_url' => 'http://127.0.0.1:4319/rpc',
            'gateway_agent' => 'opencode',
            'allowed_methods' => [
                'session/new',
            ],
        ]);
        $tool = new InvokeAcpAgentTool($thread, $actor);
        $response = json_decode($tool->handle(new ToolRequest([
            'agent' => 'opencode',
            'method' => 'session/new',
            'params' => [
                'title' => 'Gateway coding session',
            ],
            'rpc_id' => 'rpc-gateway-1',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($response['ok']);
        $this->assertSame('gateway-session-1', data_get($response, 'result.session.id'));

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'http://127.0.0.1:4319/rpc'
                && $request['agent'] === 'opencode'
                && $request['method'] === 'session/new'
                && $request['id'] === 'rpc-gateway-1'
                && data_get($request['params'], 'title') === 'Gateway coding session';
        });
    }

    public function test_it_builds_opencode_compatible_payloads(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            return match ($request['method']) {
                'initialize' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'protocolVersion' => 1,
                    ],
                ]),
                'session/new' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'sessionId' => 'opencode-session-1',
                    ],
                ]),
                'session/prompt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'stopReason' => 'end_turn',
                    ],
                ]),
                'session/load' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'sessionId' => 'opencode-session-1',
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        [$thread, $actor] = $this->conversationContext();
        $this->registerAcpAgentConnection($thread, 'opencode', [
            'label' => 'OpenCode Gateway',
            'transport' => 'http',
            'endpoint_url' => 'http://127.0.0.1:4319/rpc',
            'gateway_agent' => 'opencode',
            'allowed_methods' => [
                'initialize',
                'session/new',
                'session/load',
                'session/prompt',
            ],
            'client' => [
                'id' => 'figurate',
                'name' => 'Figurate',
                'version' => '0.1.0',
                'capabilities' => [],
            ],
            'initialize_payload' => [
                'protocolVersion' => 1,
            ],
            'session' => [
                'reuse' => 'thread',
                'create_method' => 'session/new',
                'load_method' => 'session/load',
                'prompt_method' => 'session/prompt',
                'id_argument' => 'sessionId',
                'prompt_argument' => 'prompt',
                'prompt_mode' => 'content_blocks',
                'load_after_prompt' => true,
                'create_params' => [
                    'cwd' => base_path(),
                    'mcpServers' => [],
                ],
                'load_params' => [
                    'cwd' => base_path(),
                    'mcpServers' => [],
                ],
            ],
        ]);
        $tool = new DelegateAcpTaskTool($thread, $actor);
        $response = json_decode($tool->handle(new ToolRequest([
            'agent' => 'opencode',
            'message' => 'Reply with pong.',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($response['ok']);
        $this->assertSame('opencode-session-1', $response['session_id']);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'http://127.0.0.1:4319/rpc'
                && $request['method'] === 'initialize'
                && data_get($request['params'], 'protocolVersion') === 1;
        });

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'http://127.0.0.1:4319/rpc'
                && $request['method'] === 'session/new'
                && data_get($request['params'], 'cwd') === base_path()
                && data_get($request['params'], 'mcpServers') === [];
        });

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'http://127.0.0.1:4319/rpc'
                && $request['method'] === 'session/prompt'
                && data_get($request['params'], 'sessionId') === 'opencode-session-1'
                && data_get($request['params'], 'prompt.0.type') === 'text'
                && data_get($request['params'], 'prompt.0.text') === 'Reply with pong.';
        });
    }

    public function test_it_delegates_to_an_outbound_acp_agent_and_persists_the_remote_task_link(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            return match ($request['method']) {
                'initialize' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'server' => [
                            'name' => 'Gemini ACP Bridge',
                        ],
                    ],
                ]),
                'authenticate' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'authenticated' => true,
                    ],
                ]),
                'session/new' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'session' => [
                            'id' => 'remote-session-1',
                        ],
                    ],
                ]),
                'session/prompt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'task' => [
                            'id' => 'remote-task-1',
                            'status' => [
                                'state' => 'submitted',
                            ],
                        ],
                    ],
                ]),
                'session/load' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'session' => [
                            'id' => 'remote-session-1',
                        ],
                        'status' => [
                            'state' => 'submitted',
                        ],
                    ],
                ]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'error' => [
                        'code' => -32601,
                        'message' => 'Unknown method',
                    ],
                ], 404),
            };
        });

        [$thread, $actor] = $this->conversationContext();
        $this->registerAcpAgentConnection($thread, 'gemini', [
            'label' => 'Gemini CLI Bridge',
            'transport' => 'http',
            'endpoint_url' => 'https://acp.example/rpc',
            'allowed_methods' => [
                'initialize',
                'authenticate',
                'session/new',
                'session/load',
                'session/prompt',
            ],
            'client' => [
                'id' => 'figurate',
                'name' => 'Figurate',
                'version' => '0.1.0',
                'capabilities' => [
                    'streaming' => false,
                ],
            ],
            'authenticate_payload' => [
                'token' => 'bridge-token',
            ],
            'session' => [
                'reuse' => 'thread',
                'create_method' => 'session/new',
                'load_method' => 'session/load',
                'prompt_method' => 'session/prompt',
                'id_argument' => 'session_id',
                'prompt_argument' => 'prompt',
                'load_after_prompt' => true,
            ],
        ]);
        $tool = new DelegateAcpTaskTool($thread, $actor);
        $response = json_decode($tool->handle(new ToolRequest([
            'agent' => 'gemini',
            'message' => 'Review this patch set.',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($response['ok']);
        $this->assertSame('prompt', $response['stage']);
        $this->assertSame('remote-session-1', $response['session_id']);
        $this->assertSame('remote-task-1', $response['task_id']);
        $this->assertSame('submitted', $response['state']);

        $link = TaskRecord::fromEvent(
            ThreadEvent::query()->where('event_key', 'agent_task')->latest('id')->firstOrFail()
        );
        $this->assertInstanceOf(TaskRecord::class, $link);
        $this->assertSame(ThreadEvent::LayerExecution, $link->event->layer);
        $this->assertSame(AgentEvents::Acp, $link->event->kind);
        $this->assertSame('task.snapshot', $link->event->operation);
        $this->assertSame('submitted', $link->event->state);
        $this->assertSame('submitted', $link->status);
        $this->assertSame('acp', $link->protocol);
        $this->assertSame('gemini', data_get($link->remote, 'agent_id'));
        $this->assertSame('remote-session-1', data_get($link->remote, 'session_id'));
        $this->assertSame('remote-task-1', data_get($link->remote, 'task_id'));
        $this->assertSame('remote-task-1', data_get($link->lastPayload, 'prompt.result.task.id'));
        $this->assertSame('remote-session-1', data_get($link->lastPayload, 'session_snapshot.result.session.id'));

        Http::assertSentCount(5);
        Http::assertSent(function (HttpRequest $request): bool {
            return $request['method'] === 'session/prompt'
                && data_get($request['params'], 'session_id') === 'remote-session-1'
                && data_get($request['params'], 'prompt') === 'Review this patch set.';
        });

        $event = ThreadEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('acp_delegate_tool', $event->event_key);
        $this->assertSame(AgentEvents::Acp, $event->kind);
        $this->assertSame('delegate.submitted', $event->operation);
    }

    public function test_it_reuses_the_existing_remote_acp_session_for_the_same_thread(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            return match ($request['method']) {
                'initialize' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => ['ok' => true],
                ]),
                'session/new' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'session' => ['id' => 'sticky-session-1'],
                    ],
                ]),
                'session/prompt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'task' => [
                            'id' => 'sticky-task-1',
                            'status' => [
                                'state' => 'submitted',
                            ],
                        ],
                    ],
                ]),
                'session/load' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'session' => ['id' => 'sticky-session-1'],
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        [$thread, $actor] = $this->conversationContext();
        $this->registerAcpAgentConnection($thread, 'codex', [
            'label' => 'Codex Bridge',
            'transport' => 'http',
            'endpoint_url' => 'https://acp.example/rpc',
            'allowed_methods' => [
                'initialize',
                'session/new',
                'session/load',
                'session/prompt',
            ],
            'session' => [
                'reuse' => 'thread',
                'create_method' => 'session/new',
                'load_method' => 'session/load',
                'prompt_method' => 'session/prompt',
            ],
        ]);
        $tool = new DelegateAcpTaskTool($thread, $actor);

        json_decode($tool->handle(new ToolRequest([
            'agent' => 'codex',
            'message' => 'First pass.',
        ])), true, flags: JSON_THROW_ON_ERROR);

        Http::fake(function (HttpRequest $request) {
            return match ($request['method']) {
                'initialize' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => ['ok' => true],
                ]),
                'session/prompt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'task' => [
                            'id' => 'sticky-task-2',
                            'status' => [
                                'state' => 'submitted',
                            ],
                        ],
                    ],
                ]),
                'session/load' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'result' => [
                        'session' => ['id' => 'sticky-session-1'],
                    ],
                ]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'] ?? 'x',
                    'error' => [
                        'code' => -32601,
                        'message' => 'Unexpected method',
                    ],
                ], 400),
            };
        });

        $response = json_decode($tool->handle(new ToolRequest([
            'agent' => 'codex',
            'message' => 'Second pass.',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($response['ok']);
        $this->assertSame('sticky-session-1', $response['session_id']);
        $this->assertSame('submitted', $response['state']);

        Http::assertSentCount(3);
        Http::assertSent(function (HttpRequest $request): bool {
            return $request['method'] === 'session/prompt'
                && data_get($request['params'], 'prompt') === 'Second pass.';
        });
        Http::assertNotSent(function (HttpRequest $request): bool {
            return $request['method'] === 'session/new';
        });
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
    protected function registerAcpAgentConnection(Thread $thread, string $agentId, array $config = []): void
    {
        $channel = Channel::factory()->create([
            'driver' => AgentChannels::Acp,
            'server' => $agentId,
            'name' => $config['label'] ?? ucfirst($agentId).' ACP',
            'label' => $config['label'] ?? ucfirst($agentId).' ACP',
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
