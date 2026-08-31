<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\ValueObjects;

use InvalidArgumentException;

final readonly class ManagedDevice
{
    /** @param list<string> $capabilities
     *  @param list<array{name: string, protocol: string, url?: string, credential_ref?: string}> $endpoints */
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public ?string $clientId = null,
        public array $capabilities = [],
        public array $endpoints = [],
    ) {
        if (trim($this->id) === '' || trim($this->kind) === '') {
            throw new InvalidArgumentException('Device ID and kind are required.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'client_id' => $this->clientId,
            'capabilities' => $this->capabilities,
            'endpoints' => $this->endpoints,
        ];
    }
}
