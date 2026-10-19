<?php

namespace Webong\Gouge\Ai\Middleware\Workflows;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class ComposeAndRouteResponse
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $policy = implode("\n", [
            'Response composition workflow (system policy):',
            '- Compose one cohesive reply for the current thread turn.',
            '- Include a concrete next action aligned to the current work stage.',
            '- If multiple presenters exist, avoid contradicting existing presenter outcomes.',
            '- Keep routing assumptions thread-local; do not reference external threads.',
        ]);

        return $next($prompt->prepend($policy));
    }
}
