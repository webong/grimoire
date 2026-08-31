<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Contracts;

use Grimlock\LaravelAi\ValueObjects\ManagedConnector;
use Grimlock\LaravelAi\ValueObjects\ToolInvocationResult;

interface ConnectorInvoker
{
    /** @param array<string, mixed> $arguments */
    public function invoke(
        ManagedConnector $connector,
        string $operation,
        array $arguments,
        ?string $idempotencyKey = null,
        ?int $timeoutMs = null,
    ): ToolInvocationResult;
}
