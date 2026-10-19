<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Support;

use Grimlock\LaravelAi\Contracts\ClientRegistry;
use Grimlock\LaravelAi\Contracts\ConnectorRegistry;
use Grimlock\LaravelAi\Contracts\DeviceRegistry;
use Grimlock\LaravelAi\Contracts\SkillCatalog;
use Grimlock\LaravelAi\ValueObjects\ManagedClient;
use Grimlock\LaravelAi\ValueObjects\ManagedConnector;
use Grimlock\LaravelAi\ValueObjects\ManagedDevice;
use Grimlock\LaravelAi\ValueObjects\ManagedSkill;

// InMemoryRegistry is a development and conformance implementation. Production
// hosts replace it with a provider backed by their database or Grimlock API.
final class InMemoryRegistry implements ClientRegistry, ConnectorRegistry, DeviceRegistry, SkillCatalog
{
    /** @var array<string, ManagedClient> */
    private array $clients = [];

    /** @var array<string, ManagedConnector> */
    private array $connectors = [];

    /** @var array<string, ManagedDevice> */
    private array $devices = [];

    /** @var array<string, ManagedSkill> */
    private array $skills = [];

    public function upsert(ManagedClient|ManagedConnector|ManagedDevice $record): void
    {
        match (true) {
            $record instanceof ManagedClient => $this->clients[$record->id] = $record,
            $record instanceof ManagedConnector => $this->connectors[$record->id] = $record,
            $record instanceof ManagedDevice => $this->devices[$record->id] = $record,
        };
    }

    public function addSkill(ManagedSkill $skill): void
    {
        $this->skills[$skill->id] = $skill;
    }

    public function client(string $clientId): ?ManagedClient
    {
        return $this->clients[$clientId] ?? null;
    }

    public function connector(string $connectorId): ?ManagedConnector
    {
        return $this->connectors[$connectorId] ?? null;
    }

    public function device(string $deviceId): ?ManagedDevice
    {
        return $this->devices[$deviceId] ?? null;
    }

    public function clients(): array
    {
        return array_values($this->clients);
    }

    public function devices(): array
    {
        return array_values($this->devices);
    }

    public function available(): array
    {
        return array_values(array_filter(
            $this->connectors,
            fn (ManagedConnector $connector): bool => $connector->status === ManagedConnector::STATUS_ACTIVE,
        ));
    }

    public function discover(string $query = '', int $limit = 10, bool $searchContent = false): array
    {
        $query = mb_strtolower(trim($query));
        $limit = max(1, min(25, $limit));
        $matches = array_filter($this->skills, function (ManagedSkill $skill) use ($query): bool {
            return $query === '' || str_contains(mb_strtolower($skill->name.' '.$skill->description), $query);
        });

        return array_slice(array_values($matches), 0, $limit);
    }

}
