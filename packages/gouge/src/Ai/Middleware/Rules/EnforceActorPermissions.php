<?php

namespace Webong\Gouge\Ai\Middleware\Rules;

use App\Models\Server\Thread;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class EnforceActorPermissions
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $thread = $this->resolveThread($prompt);
        $actor = property_exists($prompt->agent, 'actor') ? $prompt->agent->actor : null;

        $policy = implode("\n", [
            'Actor permission rules:',
            '- Respond only within your assigned presenter capabilities.',
            '- Do not perform actions the actor cannot perform in this space/thread.',
            '- If authorization is uncertain, ask for clarification before taking action.',
            '- Never reveal sensitive state to unauthorized participants.',
        ]);

        if ($thread && $actor) {
            $policy .= "\n".'- Thread id: '.$thread->id."\n".'- Actor id: '.$actor->id;
        }

        return $next($prompt->append($policy));
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
