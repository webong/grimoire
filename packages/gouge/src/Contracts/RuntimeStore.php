<?php

namespace Webong\Gouge\Contracts;

use Webong\Gouge\Runtime\AgentRuntimeCancellation;
use Webong\Gouge\Runtime\AgentRuntimeCorrelation;
use Webong\Gouge\Runtime\AgentTurn;
use Webong\Gouge\Runtime\ExecutionResult;

interface RuntimeStore
{
    /** Atomically claim an idle session and new run; identical replays return false. */
    public function reserve(AgentTurn $turn): bool;

    public function history(AgentRuntimeCorrelation $correlation): array;

    public function start(AgentRuntimeCorrelation $correlation): bool;

    public function finish(AgentRuntimeCorrelation $correlation, ?ExecutionResult $result, ?string $error = null): void;

    public function cancel(AgentRuntimeCorrelation $correlation): AgentRuntimeCancellation;

    public function events(AgentRuntimeCorrelation $correlation): iterable;
}
