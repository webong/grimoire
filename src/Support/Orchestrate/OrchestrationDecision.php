<?php

namespace Webong\LaravelGouge\Support\Orchestrate;

use App\Models\Server\Thread;

class OrchestrationDecision
{
    /**
     * @param  list<array<string, mixed>>  $actions
     */
    public function __construct(
        public Thread $thread,
        public string $responderType,
        public ?string $responderKey,
        public array $actions = [],
    ) {}
}
