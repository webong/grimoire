<?php

namespace Webong\LaravelGouge\Ai\Middleware\Rules;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class RequireEvidenceForDecisions
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        return $next($prompt->append(implode("\n", [
            'Decision evidence rules:',
            '- Ground every decision in thread context, tool output, or explicit user input.',
            '- When evidence is weak, state uncertainty and ask one focused question.',
            '- Do not claim completed operations without observable evidence.',
        ])));
    }
}
