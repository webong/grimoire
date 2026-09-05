<?php

namespace Webong\LaravelGouge\Ai\Middleware\Rules;

use App\Models\Server\Thread;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class EnforceThreadParticipation
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $thread = $this->resolveThread($prompt);
        $actor = property_exists($prompt->agent, 'actor') ? $prompt->agent->actor : null;

        if (! $thread || ! $actor) {
            return $next($prompt->append(implode("\n", [
                'Thread participation rules:',
                '- Proceed only when thread participation is valid.',
                '- If participation cannot be verified, avoid sensitive thread-specific claims.',
            ])));
        }

        $isParticipant = $thread->actors()
            ->whereMorphedTo('actorable', $actor)
            ->exists();

        $rule = $isParticipant
            ? '- Actor is a valid participant for this thread.'
            : '- Actor participation is not verifiable; avoid state mutation and ask for remediation.';

        return $next($prompt->append(implode("\n", [
            'Thread participation rules:',
            $rule,
            '- Do not infer membership across different threads/spaces.',
        ])));
    }

    protected function resolveThread(AgentPrompt $prompt): ?Thread
    {
        $agent = $prompt->agent;

        if (! property_exists($agent, 'thread')) {
            return null;
        }

        $thread = $agent->thread;

        return $thread instanceof Thread ? $thread : null;
    }
}
