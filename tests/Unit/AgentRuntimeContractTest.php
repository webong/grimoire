<?php

namespace Webong\LaravelGouge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Webong\LaravelGouge\Contracts\AgentRuntime;
use Webong\LaravelGouge\Runtime\AgentTurn;

class AgentRuntimeContractTest extends TestCase
{
    public function test_the_runtime_contract_and_turn_correlation_are_package_owned(): void
    {
        $turn = new AgentTurn(
            threadId: 10,
            threadUuid: 'thread-1',
            postId: 20,
            postUlid: 'post-1',
            userId: 30,
            threadActorId: 40,
            actorKey: 'coordinator_agent',
            prompt: 'Review this.',
            broadcastChannel: 'threads.thread-1',
        );
        $this->assertTrue(interface_exists(AgentRuntime::class));
        $this->assertSame(64, strlen($turn->localCorrelationId()));
    }
}
