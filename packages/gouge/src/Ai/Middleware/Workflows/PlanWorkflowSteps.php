<?php

namespace Webong\Gouge\Ai\Middleware\Workflows;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class PlanWorkflowSteps
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $plan = implode("\n", [
            'Work planning workflow (system policy):',
            '- Build a short internal plan before final response.',
            '- Plan order: analyze thread state -> decide tool needs -> execute tools -> synthesize answer.',
            '- Ask at most one targeted follow-up question when critical context is missing.',
            '- If context is sufficient, provide a direct next action without stalling.',
        ]);

        return $next($prompt->prepend($plan));
    }
}
