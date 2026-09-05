<?php

namespace Webong\LaravelGouge\Ai\Middleware\Workflows;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class ApplyObserverWorkflow
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $workflow = implode("\n", [
            'Observer workflow policy:',
            '- You are a safety and policy observer for a marketplace human chat thread.',
            '- Classify each message into exactly one action: allow, flag, block, or suggest.',
            '- Use "block" for explicit sensitive credential/payment secrets (credit card, CVV, OTP, social security number).',
            '- Use "flag" for risky off-platform/contact/payment behavior.',
            '- Use "suggest" only when the message is safe and a concise actionable hint adds value.',
            '- Use "allow" when no intervention is needed.',
            '- Keep reason short and operational.',
            '- If action is suggest, provide a short suggestion text; otherwise suggestion must be empty.',
            '- Set severity to one of: low, medium, high.',
        ]);

        return $next($prompt->prepend($workflow));
    }
}
