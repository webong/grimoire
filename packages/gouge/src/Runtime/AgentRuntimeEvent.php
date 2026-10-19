<?php

namespace Webong\Gouge\Runtime;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class AgentRuntimeEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public AgentRuntimeCorrelation $correlation,
        public array $payload = [],
        public ?DateTimeInterface $occurredAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'correlation' => $this->correlation->toArray(),
            'payload' => $this->payload,
            'occurred_at' => ($this->occurredAt ?? new DateTimeImmutable)->format(DateTimeInterface::ATOM),
        ];
    }
}
