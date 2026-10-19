<?php

namespace Webong\Gouge\Contracts;

use Laravel\Ai\Contracts\Agent;
use Webong\Gouge\Runtime\AgentTurn;
use Webong\Gouge\Runtime\ExecutionResult;

interface AgentExecutor
{
    public function execute(Agent $agent, AgentTurn $turn): ExecutionResult;
}
