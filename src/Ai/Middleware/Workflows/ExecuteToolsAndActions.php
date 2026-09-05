<?php

namespace Webong\LaravelGouge\Ai\Middleware\Workflows;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class ExecuteToolsAndActions
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $policy = implode("\n", [
            'Tool execution workflow (system policy):',
            '- Use available tools when state or IDs are uncertain.',
            '- Prefer fewer, high-value tool calls over broad probing.',
            '- If a tool fails or is unavailable, acknowledge limitation and continue with safe fallback.',
            '- Never fabricate tool output, IDs, statuses, or side effects.',
        ]);

        return $next($prompt->prepend($policy));
    }
}
