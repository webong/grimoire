<?php

namespace Webong\Gouge\Ai\Middleware\Workflows;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Webong\Gouge\Support\AgentActors;

class SelectPresenters
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $thread = $this->resolveThread($prompt);

        if (! $thread) {
            return $next($prompt);
        }

        $presenterActors = $thread->actors()
            ->where('role', AgentActors::RolePresenter)
            ->where('status', ThreadActor::StatusActive)
            ->orderBy('priority')
            ->get();
        $presenterCount = $presenterActors->count();
        $presenterKeys = $presenterActors
            ->map(fn ($actor): ?string => $actor->actorName())
            ->filter()
            ->values();

        $primaryPresenter = $presenterKeys->first() ?? 'none';

        $policy = implode("\n", [
            'Presenter routing policy (system policy):',
            "- Total presenters: {$presenterCount}",
            "- Primary presenter actor key: {$primaryPresenter}",
            '- If multiple presenters exist, treat non-primary presenters as observer-like collaborators.',
            '- Non-primary presenters may respond, but must avoid conflicting directives.',
            '- Keep your response scoped to your presenter role and thread objective.',
        ]);

        return $next($prompt->prepend($policy));
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
