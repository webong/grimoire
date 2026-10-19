<?php

namespace Webong\Gouge\Contracts;

use Webong\Gouge\Runtime\AgentRuntimeCancellation;
use Webong\Gouge\Runtime\AgentRuntimeCorrelation;
use Webong\Gouge\Runtime\AgentRuntimeEvent;
use Webong\Gouge\Runtime\AgentTurn;

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
