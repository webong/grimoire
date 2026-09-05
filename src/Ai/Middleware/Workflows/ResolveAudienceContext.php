<?php

namespace Webong\LaravelGouge\Ai\Middleware\Workflows;

use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Webong\LaravelGouge\Ai\Support\ThreadContextResolver;

class ResolveAudienceContext
{
    public function __construct(
        protected ThreadContextResolver $threadContextResolver = new ThreadContextResolver,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $thread = $this->resolveThread($prompt);
        $actor = $this->resolveActor($prompt);

        if (! $thread || ! $actor) {
            return $next($prompt);
        }

        $space = $this->threadContextResolver->resolveSpace($thread);
        $participantCount = $this->resolveParticipantCount($thread, $space);
        $conversationMode = $participantCount > 2 ? 'group' : 'direct';
        $audience = $conversationMode === 'group' ? 'group' : 'member';

        $context = implode("\n", [
            'Audience context (system policy):',
            '- Speaker role: member',
            "- Audience: {$audience}",
            "- Conversation mode: {$conversationMode}",
            "- Participant count: {$participantCount}",
            '- Adapt reply to the audience and avoid cross-party leakage.',
            '- Keep participant terms generic unless domain context supplies specific roles.',
        ]);

        return $next($prompt->prepend($context));
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

    protected function resolveActor(AgentPrompt $prompt): ?User
    {
        $agent = $prompt->agent;

        if (! property_exists($agent, 'actor')) {
            return null;
        }

        $actor = $agent->actor;

        return $actor instanceof User ? $actor : null;
    }

    protected function resolveParticipantCount(Thread $thread, ?Space $space): int
    {
        if ($space) {
            return max(
                1,
                (int) $space->actorStates()
                    ->where('status', 'active')
                    ->where('actorable_type', (new User)->getMorphClass())
                    ->distinct('actorable_id')
                    ->count('actorable_id')
            );
        }

        return max(
            1,
            (int) $thread->actors()
                ->where('status', ThreadActor::StatusActive)
                ->where('actorable_type', (new User)->getMorphClass())
                ->distinct('actorable_id')
                ->count('actorable_id')
        );
    }
}
