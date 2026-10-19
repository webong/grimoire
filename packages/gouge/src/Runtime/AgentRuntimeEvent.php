<?php

namespace Webong\Gouge\Runtime;

final readonly class AgentRuntimeEvent
{
    public function __construct(
        public string $type,
        public AgentRuntimeCorrelation $correlation,
        public int $sequence,
        public array $payload,
        public string $occurredAt,
    ) {}

    public function toArray(): array
    {
        return ['type' => $this->type, 'correlation' => $this->correlation->toArray(),
            'sequence' => $this->sequence, 'payload' => $this->payload, 'occurred_at' => $this->occurredAt];
    }
}
