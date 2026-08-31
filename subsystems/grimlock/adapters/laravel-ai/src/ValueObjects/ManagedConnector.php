<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\ValueObjects;

use InvalidArgumentException;

final readonly class ManagedConnector
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DISABLED = 'disabled';

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_BIDIRECTIONAL = 'bidirectional';

    /** @param list<string> $allowedOperations
     *  @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $protocol,
        public string $transport,
        public string $direction,
        public string $status,
        public array $allowedOperations,
        public ?string $endpoint = null,
        public ?string $handler = null,
        public ?string $credentialRef = null,
        public ?string $clientId = null,
        public ?string $deviceId = null,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '' || trim($this->protocol) === '' || trim($this->transport) === '') {
            throw new InvalidArgumentException('Connector ID, protocol, and transport are required.');
        }
        if (! in_array($this->direction, [self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND, self::DIRECTION_BIDIRECTIONAL], true)) {
            throw new InvalidArgumentException('Connector direction is invalid.');
        }
        if (! in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_DISABLED], true)) {
            throw new InvalidArgumentException('Connector status is invalid.');
        }
        if (trim((string) $this->endpoint) === '' && trim((string) $this->handler) === '') {
            throw new InvalidArgumentException('Connector endpoint or handler is required.');
        }
    }

    public function allows(string $operation): bool
    {
        return in_array('*', $this->allowedOperations, true)
            || in_array(trim($operation), $this->allowedOperations, true);
    }

    public function maySend(): bool
    {
        return $this->direction === self::DIRECTION_OUTBOUND || $this->direction === self::DIRECTION_BIDIRECTIONAL;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'protocol' => $this->protocol,
            'transport' => $this->transport,
            'direction' => $this->direction,
            'status' => $this->status,
            'allowed_operations' => $this->allowedOperations,
            'has_endpoint' => trim((string) $this->endpoint) !== '',
            'has_handler' => trim((string) $this->handler) !== '',
            'client_id' => $this->clientId,
            'device_id' => $this->deviceId,
            'metadata' => $this->metadata,
        ];
    }
}
