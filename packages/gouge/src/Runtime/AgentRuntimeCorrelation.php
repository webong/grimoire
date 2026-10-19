<?php

namespace Webong\Gouge\Runtime;

final readonly class AgentRuntimeCorrelation
{
    public function __construct(
        public string $scopeId,
        public string $sessionId,
        public string $runId,
        public string $runtime = 'laravel-ai',
    ) {}

    public function toArray(): array
    {
        return ['scope_id' => $this->scopeId, 'session_id' => $this->sessionId,
            'run_id' => $this->runId, 'runtime' => $this->runtime];
    }
}
