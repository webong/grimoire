<?php

namespace Webong\Gouge\Tests\Unit;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\TestCase;
use Webong\Gouge\Ai\Agents\Subs\Planner;
use Webong\Gouge\Ai\Support\SubAgents\SubAgentDispatcher;
use Webong\Gouge\Ai\Support\SubAgents\SubAgentInvocationMemory;
use Webong\Gouge\Ai\Tools\InvokeSubAgentTool;

class SubAgentInvocationComplianceTest extends TestCase
{
    public function test_dispatcher_returns_deterministic_fallback_when_sub_agent_response_is_empty(): void
    {
        $dispatcher = new class extends SubAgentDispatcher
        {
            protected function resolve(string $subAgentKey): ?Planner
            {
                return new Planner;
            }

            protected function invokeAgent(Agent $agent, string $prompt): mixed
            {
                return '';
            }
        };

        $result = $dispatcher->dispatch('planner', 'Create a plan.');

        $this->assertFalse($result['ok']);
        $this->assertSame('sub_agent_empty_response', $result['error_code']);
        $this->assertSame('Sub-agent returned no actionable output.', data_get($result, 'response.fallback_text'));
    }

    public function test_invoke_tool_blocks_when_sub_agent_is_not_allowed_for_actor(): void
    {
        $tool = $this->makeInvokeTool(
            dispatcher: new FakeDispatcher,
            allowed: false,
        );

        $response = $tool->handle(new Request([
            'sub_agent' => 'planner',
            'prompt' => 'Plan this.',
        ]));
        $payload = json_decode((string) $response, true);

        $this->assertFalse($payload['ok']);
        $this->assertSame('sub_agent_not_allowed', $payload['error_code']);
    }

    public function test_invoke_tool_enforces_trace_level_policy_limit(): void
    {
        $tool = $this->makeInvokeTool(
            dispatcher: new FakeDispatcher,
            allowed: true,
            stats: ['total' => 6, 'per_sub_agent' => 0],
            parentInvocationId: 'M1',
        );

        $response = $tool->handle(new Request([
            'sub_agent' => 'developer',
            'prompt' => 'Implement this.',
        ]));
        $payload = json_decode((string) $response, true);

        $this->assertFalse($payload['ok']);
        $this->assertSame('sub_agent_trace_limit_exceeded', $payload['error_code']);
        $this->assertSame('M1', $payload['trace_id']);
    }

    public function test_invoke_tool_propagates_parent_and_trace_identifiers_to_dispatcher(): void
    {
        $dispatcher = new FakeDispatcher;
        $tool = $this->makeInvokeTool(
            dispatcher: $dispatcher,
            allowed: true,
            stats: ['total' => 0, 'per_sub_agent' => 0],
            parentInvocationId: 'MAIN-INV-1',
        );

        $response = $tool->handle(new Request([
            'sub_agent' => 'researcher',
            'prompt' => 'Research constraints.',
        ]));
        $payload = json_decode((string) $response, true);

        $this->assertTrue($payload['ok']);
        $this->assertSame('MAIN-INV-1', $dispatcher->lastTraceId);
        $this->assertSame('MAIN-INV-1', $dispatcher->lastParentInvocationId);
        $this->assertSame('MAIN-INV-1', $payload['trace_id']);
        $this->assertSame('MAIN-INV-1', $payload['parent_invocation_id']);
    }

    /**
     * @param  array{total:int, per_sub_agent:int}  $stats
     */
    protected function makeInvokeTool(
        FakeDispatcher $dispatcher,
        bool $allowed,
        array $stats = ['total' => 0, 'per_sub_agent' => 0],
        ?string $parentInvocationId = null,
    ): InvokeSubAgentTool {
        $thread = new Thread;
        $thread->id = 1;
        $actor = new User;
        $actor->id = 99;
        $actor->uuid = 'user-99';

        return new class($thread, $actor, null, $dispatcher, $allowed, $stats, $parentInvocationId) extends InvokeSubAgentTool
        {
            /**
             * @param  array{total:int, per_sub_agent:int}  $stats
             */
            public function __construct(
                Thread $thread,
                User $actor,
                ?object $threadActor,
                FakeDispatcher $dispatcher,
                protected bool $allowed,
                protected array $stats,
                protected ?string $forcedParentInvocationId,
            ) {
                parent::__construct($thread, $actor, null, $dispatcher, new SubAgentInvocationMemory);
            }

            protected function isSubAgentAllowedForActor(string $subAgent): bool
            {
                return $this->allowed;
            }

            protected function resolveParentInvocationId(?string $explicitParentInvocationId, ?string $memoryParentInvocationId = null): ?string
            {
                return $explicitParentInvocationId ?? $memoryParentInvocationId ?? $this->forcedParentInvocationId;
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

            protected function recentInvocationStats(string $traceId, string $subAgent): array
            {
                return $this->stats;
            }

            protected function enforcePolicyLimits(string $subAgent, string $traceId): ?array
            {
                if (($this->stats['total'] ?? 0) >= 6) {
                    return [
                        'ok' => false,
                        'error_code' => 'sub_agent_trace_limit_exceeded',
                        'error' => 'Sub-agent invocation limit exceeded for this trace.',
                        'sub_agent' => $subAgent,
                        'trace_id' => $traceId,
                        'limits' => [
                            'max_invocations_per_trace' => 6,
                            'max_invocations_per_sub_agent_per_trace' => 3,
                        ],
                    ];
                }

                if (($this->stats['per_sub_agent'] ?? 0) >= 3) {
                    return [
                        'ok' => false,
                        'error_code' => 'sub_agent_per_agent_limit_exceeded',
                        'error' => 'Sub-agent invocation limit exceeded for this sub-agent in current trace.',
                        'sub_agent' => $subAgent,
                        'trace_id' => $traceId,
                        'limits' => [
                            'max_invocations_per_trace' => 6,
                            'max_invocations_per_sub_agent_per_trace' => 3,
                        ],
                    ];
                }

                return null;
            }

            protected function rememberInvocationContext(string $traceId, ?string $parentInvocationId, ?string $lastSubAgentInvocationId): void {}

            protected function recordInvocationEvent(string $subAgent, array $result, bool $successful): void {}

            protected function persistSuccessfulInvocation(string $subAgent, array $result): void {}
        };
    }
}

class FakeDispatcher extends SubAgentDispatcher
{
    public ?string $lastTraceId = null;

    public ?string $lastParentInvocationId = null;

    public function dispatch(
        string $subAgentKey,
        string $prompt,
        array $context = [],
        ?string $traceId = null,
        ?string $parentInvocationId = null,
    ): array {
        $this->lastTraceId = $traceId;
        $this->lastParentInvocationId = $parentInvocationId;

        return [
            'ok' => true,
            'sub_agent' => $subAgentKey,
            'trace_id' => $traceId,
            'invocation_id' => 'SUB-INV-1',
            'parent_invocation_id' => $parentInvocationId,
            'response' => [
                'text' => 'done',
                'conversation_id' => null,
                'provider_invocation_id' => null,
                'fallback_text' => null,
            ],
        ];
    }
}
