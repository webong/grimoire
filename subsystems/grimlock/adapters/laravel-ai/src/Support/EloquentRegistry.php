<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Support;

use Grimlock\LaravelAi\Contracts\ClientRegistry;
use Grimlock\LaravelAi\Contracts\ConnectorRegistry;
use Grimlock\LaravelAi\Contracts\DeviceRegistry;
use Grimlock\LaravelAi\Contracts\SkillCatalog;
use Grimlock\LaravelAi\Database\Models\Client;
use Grimlock\LaravelAi\Database\Models\Connector;
use Grimlock\LaravelAi\Database\Models\Device;
use Grimlock\LaravelAi\Database\Models\Resource;
use Grimlock\LaravelAi\ValueObjects\ManagedClient;
use Grimlock\LaravelAi\ValueObjects\ManagedConnector;
use Grimlock\LaravelAi\ValueObjects\ManagedDevice;
use Grimlock\LaravelAi\ValueObjects\ManagedSkill;

// EloquentRegistry is Grimlock's default Laravel persistence implementation.
// Host applications may decorate it, but do not need to duplicate the schema.
final class EloquentRegistry implements ClientRegistry, ConnectorRegistry, DeviceRegistry, SkillCatalog
{
    public function upsert(ManagedClient|ManagedConnector|ManagedDevice $record): void
    {
        match (true) {
            $record instanceof ManagedClient => $this->saveClient($record),
            $record instanceof ManagedConnector => $this->saveConnector($record),
            $record instanceof ManagedDevice => $this->saveDevice($record),
        };
    }

    public function addSkill(ManagedSkill $skill): void
    {
        Resource::query()->updateOrCreate(
            ['id' => $skill->id],
            [
                'kind' => 'Skill',
                'name' => $skill->name,
                'version' => '1.0.0',
                'digest' => $skill->digest,
                'spec' => [
                    'description' => $skill->description,
                    'contentRef' => $skill->contentRef,
                    'digest' => $skill->digest,
                    'references' => $skill->references,
                ],
            ],
        );
    }

    public function client(string $clientId): ?ManagedClient
    {
        $record = Client::query()->find($clientId);

        return $record instanceof Client ? $this->clientValue($record) : null;
    }

    public function clients(): array
    {
        return Client::query()->get()->map(fn (Client $record): ManagedClient => $this->clientValue($record))->all();
    }

    public function device(string $deviceId): ?ManagedDevice
    {
        $record = Device::query()->find($deviceId);

        return $record instanceof Device ? $this->deviceValue($record) : null;
    }

    public function devices(): array
    {
        return Device::query()->get()->map(fn (Device $record): ManagedDevice => $this->deviceValue($record))->all();
    }

    public function connector(string $connectorId): ?ManagedConnector
    {
        $record = Connector::query()->find($connectorId);

        return $record instanceof Connector ? $this->connectorValue($record) : null;
    }

    public function available(): array
    {
        return Connector::query()
            ->where('status', ManagedConnector::STATUS_ACTIVE)
            ->get()
            ->map(fn (Connector $record): ManagedConnector => $this->connectorValue($record))
            ->all();
    }

    public function discover(string $query = '', int $limit = 10): array
    {
        $records = Resource::query()
            ->where('kind', 'Skill')
            ->when(trim($query) !== '', fn ($builder) => $builder->where('name', 'like', '%'.trim($query).'%'))
            ->limit(max(1, min(25, $limit)))
            ->get();

        return $records->map(function (Resource $record): ManagedSkill {
            $spec = is_array($record->spec) ? $record->spec : [];

            return new ManagedSkill(
                (string) $record->id,
                (string) $record->name,
                (string) ($spec['description'] ?? ''),
                (string) ($spec['contentRef'] ?? ''),
                (string) $record->digest,
                is_array($spec['references'] ?? null) ? $spec['references'] : [],
            );
        })->all();
    }

    private function saveClient(ManagedClient $client): void
    {
        Client::query()->updateOrCreate(['id' => $client->id], [
            'kind' => $client->kind, 'device_identifier' => $client->deviceIdentifier,
            'platform' => $client->platform, 'app_version' => $client->appVersion, 'last_seen_at' => $client->lastSeenAt, 'metadata' => $client->metadata,
        ]);
    }

    private function saveDevice(ManagedDevice $device): void
    {
        Device::query()->updateOrCreate(['id' => $device->id], [
            'client_id' => $device->clientId, 'kind' => $device->kind, 'label' => $device->label,
            'capabilities' => $device->capabilities, 'endpoints' => $device->endpoints,
        ]);
    }

    private function saveConnector(ManagedConnector $connector): void
    {
        Connector::query()->updateOrCreate(['id' => $connector->id], [
            'client_id' => $connector->clientId, 'device_id' => $connector->deviceId,
            'name' => $connector->id, 'protocol' => $connector->protocol, 'transport' => $connector->transport,
            'direction' => $connector->direction, 'status' => $connector->status, 'endpoint' => $connector->endpoint,
            'handler' => $connector->handler, 'credential_ref' => $connector->credentialRef,
            'allowed_operations' => $connector->allowedOperations, 'metadata' => $connector->metadata,
        ]);
    }

    private function clientValue(Client $record): ManagedClient
    {
        return new ManagedClient((string) $record->id, (string) $record->kind, (string) $record->device_identifier, $record->platform, $record->app_version, $record->last_seen_at, is_array($record->metadata) ? $record->metadata : []);
    }

    private function deviceValue(Device $record): ManagedDevice
    {
        return new ManagedDevice((string) $record->id, (string) $record->kind, (string) $record->label, $record->client_id, is_array($record->capabilities) ? $record->capabilities : [], is_array($record->endpoints) ? $record->endpoints : []);
    }

    private function connectorValue(Connector $record): ManagedConnector
    {
        return new ManagedConnector((string) $record->id, (string) $record->protocol, (string) $record->transport, (string) $record->direction, (string) $record->status, is_array($record->allowed_operations) ? $record->allowed_operations : [], $record->endpoint, $record->handler, $record->credential_ref, $record->client_id, $record->device_id, is_array($record->metadata) ? $record->metadata : []);
    }
}
