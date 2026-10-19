<?php

namespace Webong\Gouge\Contracts;

use Laravel\Ai\Contracts\Agent;
use Webong\Gouge\Runtime\AgentTurn;

interface AgentFactory
{
    /** Resolve a trusted exact snapshot (or verify a registry artifact) and bind admitted native tools. */
    public function make(AgentTurn $turn, array $history): Agent;
}
