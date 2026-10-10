<?php

namespace Webong\Gouge\Ai\Middleware\Rules;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class EnforceToolBudgetAndTimeouts
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        return $next($prompt->append(implode("\n", [
            'Tool budget rules:',
            '- Keep tool usage minimal and targeted per turn.',
            '- Avoid repeated calls to the same tool unless new evidence requires it.',
            '- If tool latency or failure occurs, continue with the best bounded response.',
        ])));
    }
}
