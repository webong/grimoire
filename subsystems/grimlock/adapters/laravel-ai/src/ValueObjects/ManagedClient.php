<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ManagedClient
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $kind,
        public string $deviceIdentifier,
        public ?string $platform = null,
        public ?string $appVersion = null,
        public ?DateTimeImmutable $lastSeenAt = null,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '' || trim($this->kind) === '' || trim($this->deviceIdentifier) === '') {
            throw new InvalidArgumentException('Client ID, kind, and device identifier are required.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'device_identifier' => $this->deviceIdentifier,
            'platform' => $this->platform,
            'app_version' => $this->appVersion,
            'last_seen_at' => $this->lastSeenAt?->format(DATE_ATOM),
            'metadata' => $this->metadata,
        ];
    }
}
