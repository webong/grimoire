<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Contracts;

use Grimlock\LaravelAi\ValueObjects\ManagedClient;

interface ClientRegistry
{
    public function upsert(ManagedClient $client): void;

    public function client(string $clientId): ?ManagedClient;

    /** @return list<ManagedClient> */
    public function clients(): array;
}
