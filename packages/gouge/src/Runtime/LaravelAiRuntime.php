<?php

namespace Webong\Gouge\Runtime;

use Throwable;
use Webong\Gouge\Contracts\AgentExecutor;
use Webong\Gouge\Contracts\AgentFactory;
use Webong\Gouge\Contracts\AgentRuntime;
use Webong\Gouge\Contracts\RuntimeStore;

final class LaravelAiRuntime implements AgentRuntime
{
    public function __construct(private RuntimeStore $store, private AgentFactory $agents, private AgentExecutor $executor) {}

    public function submitTurn(AgentTurn $turn): void
    {
        if (! $this->store->reserve($turn)) {
            return;
        }
        $correlation = $this->correlationFor($turn);
        try {
            $agent = $this->agents->make($turn, $this->store->history($correlation));
            if (! $this->store->start($correlation)) {
                return;
            }
            $result = $this->executor->execute($agent, $turn);
        } catch (Throwable $error) {
            $this->store->finish($correlation, null, $error->getMessage());
            throw $error;
        }
        // Persistence failures must never trigger another model/tool invocation.
        $this->store->finish($correlation, $result);
    }

    public function streamEvents(AgentRuntimeCorrelation $correlation): iterable
    {
        return $this->store->events($correlation);
    }

    public function cancel(AgentRuntimeCorrelation $correlation): AgentRuntimeCancellation
    {
        return $this->store->cancel($correlation);
    }

    public function correlationFor(AgentTurn $turn): AgentRuntimeCorrelation
    {
        return new AgentRuntimeCorrelation($turn->scopeId, $turn->sessionId, $turn->runId);
    }
}
