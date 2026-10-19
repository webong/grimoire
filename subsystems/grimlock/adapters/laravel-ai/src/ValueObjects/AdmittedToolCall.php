<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\ValueObjects;

use InvalidArgumentException;

final readonly class AdmittedToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $sessionId,
        public string $runId,
        public string $toolName,
        public string $toolVersion,
        public array $arguments,
        public string $idempotencyKey,
    ) {
        foreach ([$this->sessionId, $this->runId, $this->toolName, $this->toolVersion, $this->idempotencyKey] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Session ID, run ID, tool name, tool version, and idempotency key are required.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'toolRef' => [
                'kind' => 'Tool',
                'name' => $this->toolName,
                'version' => $this->toolVersion,
            ],
            'arguments' => $this->arguments,
            'idempotencyKey' => $this->idempotencyKey,
        ];
    }
}
