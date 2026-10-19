<?php

namespace Webong\Gouge\Ai\Middleware\Rules;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class PreventDuplicateProcessing
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        return $next($prompt->append(implode("\n", [
            'Duplicate-processing rules:',
            '- Assume idempotency is enforced by the controller boundary.',
            '- Do not generate repeated confirmations for identical prior outcomes in the same turn.',
            '- If duplicate intent is detected, summarize existing outcome instead of re-running side effects.',
        ])));
    }
}
