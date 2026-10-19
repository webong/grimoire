<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\ValueObjects;

use InvalidArgumentException;

final readonly class AdmittedToolResult
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_APPROVAL_REQUIRED = 'approval_required';
    public const STATUS_FAILED = 'failed';

    /** @param array<string, mixed>|null $output */
    private function __construct(
        public string $status,
        public ?array $output = null,
        public ?string $approvalId = null,
        public bool $replayed = false,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
        if (! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_APPROVAL_REQUIRED, self::STATUS_FAILED], true)) {
            throw new InvalidArgumentException('Admitted tool result status is invalid.');
        }
        if ($this->status === self::STATUS_APPROVAL_REQUIRED && trim((string) $this->approvalId) === '') {
            throw new InvalidArgumentException('Approval ID is required when approval is pending.');
        }
        if ($this->status === self::STATUS_FAILED && (trim((string) $this->errorCode) === '' || trim((string) $this->errorMessage) === '')) {
            throw new InvalidArgumentException('Failure code and message are required for a failed tool call.');
        }
    }

    /** @param array<string, mixed> $output */
    public static function completed(array $output, bool $replayed = false): self
    {
        return new self(self::STATUS_COMPLETED, $output, replayed: $replayed);
    }

    public static function approvalRequired(string $approvalId): self
    {
        return new self(self::STATUS_APPROVAL_REQUIRED, approvalId: $approvalId);
    }

    public static function failed(string $errorCode, string $errorMessage): self
    {
        return new self(self::STATUS_FAILED, errorCode: $errorCode, errorMessage: $errorMessage);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->status !== self::STATUS_FAILED,
            'status' => $this->status,
            'output' => $this->output,
            'approval_id' => $this->approvalId,
            'replayed' => $this->replayed,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
