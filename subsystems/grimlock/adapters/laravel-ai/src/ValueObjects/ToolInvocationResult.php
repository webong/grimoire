<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\ValueObjects;

final readonly class ToolInvocationResult
{
    /** @param array<string, mixed>|null $data */
    private function __construct(
        public bool $ok,
        public ?array $data = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function success(array $data): self
    {
        return new self(true, $data);
    }

    public static function failure(string $errorCode, string $errorMessage): self
    {
        return new self(false, null, $errorCode, $errorMessage);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'data' => $this->data,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
