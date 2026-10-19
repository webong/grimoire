<?php

namespace Webong\Gouge\Ai\Middleware\Workflows;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class PostResponseLearning
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $policy = implode("\n", [
            'Post-response workflow (system policy):',
            '- End each response with state that can be reused next turn.',
            '- Preserve continuity with the active request/order context.',
            '- Avoid re-asking details that are already confirmed in-thread.',
        ]);

        return $next($prompt->append($policy));
    }
}
