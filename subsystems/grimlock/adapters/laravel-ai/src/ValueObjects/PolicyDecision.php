<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\ValueObjects;

final readonly class PolicyDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
