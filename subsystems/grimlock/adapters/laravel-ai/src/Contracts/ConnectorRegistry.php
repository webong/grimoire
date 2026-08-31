<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Contracts;

use Grimlock\LaravelAi\ValueObjects\ManagedConnector;

interface ConnectorRegistry
{
    public function upsert(ManagedConnector $connector): void;

    public function connector(string $connectorId): ?ManagedConnector;

    /** @return list<ManagedConnector> */
    public function available(): array;
}
