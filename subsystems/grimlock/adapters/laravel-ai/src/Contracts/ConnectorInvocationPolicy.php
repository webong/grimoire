<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Contracts;

use Grimlock\LaravelAi\ValueObjects\ManagedConnector;
use Grimlock\LaravelAi\ValueObjects\PolicyDecision;

interface ConnectorInvocationPolicy
{
    public function authorize(ManagedConnector $connector, string $operation): PolicyDecision;
}
