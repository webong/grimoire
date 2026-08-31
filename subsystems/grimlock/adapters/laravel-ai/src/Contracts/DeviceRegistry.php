<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Contracts;

use Grimlock\LaravelAi\ValueObjects\ManagedDevice;

interface DeviceRegistry
{
    public function upsert(ManagedDevice $device): void;

    public function device(string $deviceId): ?ManagedDevice;

    /** @return list<ManagedDevice> */
    public function devices(): array;
}
