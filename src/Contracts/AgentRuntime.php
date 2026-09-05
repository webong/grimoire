<?php

namespace Webong\LaravelGouge\Contracts;

use Webong\LaravelGouge\Runtime\AgentRuntimeCancellation;
use Webong\LaravelGouge\Runtime\AgentRuntimeCorrelation;
use Webong\LaravelGouge\Runtime\AgentRuntimeEvent;
use Webong\LaravelGouge\Runtime\AgentTurn;

interface AgentRuntime
{
    public function submitTurn(AgentTurn $turn): void;

    /**
     * @return iterable<int, AgentRuntimeEvent>
     */
    public function streamEvents(AgentRuntimeCorrelation $correlation): iterable;

    public function cancel(AgentRuntimeCorrelation $correlation): AgentRuntimeCancellation;

    public function correlationFor(AgentTurn $turn): AgentRuntimeCorrelation;
}
