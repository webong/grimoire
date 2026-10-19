<?php

namespace Webong\Gouge\Runtime;

final readonly class AgentRuntimeCancellation
{
    public function __construct(
        public AgentRuntimeCorrelation $correlation,
        public bool $accepted,
        public bool $interrupted,
        public ?string $reason = null,
    ) {}
}
