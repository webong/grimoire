<?php

namespace Webong\Gouge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Webong\Gouge\Contracts\AgentRuntime;
use Webong\Gouge\Runtime\AgentTurn;

final class ExecuteAgentTurn implements ShouldQueue
{
    use Queueable;

    // Unknown tool effects must not be retried automatically after worker failure.
    public int $tries = 1;

    public function __construct(public AgentTurn $turn) {}

    public function handle(AgentRuntime $runtime): void
    {
        $runtime->submitTurn($this->turn);
    }
}
