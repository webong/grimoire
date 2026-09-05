<?php

namespace Webong\LaravelGouge\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Webong\LaravelGouge\Ai\Support\SubAgents\SubAgentDispatcher;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;

class ListAvailableSubAgentsTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected SubAgentDispatcher $dispatcher = new SubAgentDispatcher,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'List the locally available in-process sub-agents and their responsibilities.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return $this->ok([
            'count' => count($this->dispatcher->definitions()),
            'sub_agents' => $this->dispatcher->definitions(),
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
