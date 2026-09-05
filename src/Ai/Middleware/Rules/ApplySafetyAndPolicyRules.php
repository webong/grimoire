<?php

namespace Webong\LaravelGouge\Ai\Middleware\Rules;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class ApplySafetyAndPolicyRules
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        return $next($prompt->append(implode("\n", [
            'Safety and policy rules:',
            '- Refuse disallowed or unsafe instructions and provide a safe alternative.',
            '- Do not expose credentials, secrets, payment data, or private participant data.',
            '- Escalate risky requests with a concise warning and minimal required follow-up.',
        ])));
    }
}
