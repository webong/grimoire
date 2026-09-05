<?php

namespace Webong\LaravelGouge\Ai\Agents\Subs;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class Manager implements Agent
{
    use Promptable;

    public function key(): string
    {
        return 'manager';
    }

    public function role(): string
    {
        return 'Coordinator';
    }

    public function goal(): string
    {
        return 'Break the request into the smallest safe set of tasks and assign ownership clearly.';
    }

    /**
     * @return list<string>
     */
    public function constraints(): array
    {
        return [
            'Do not write implementation code directly.',
            'Create task boundaries that can be executed independently.',
            'Prefer predictable, testable increments over broad refactors.',
        ];
    }

    public function instructions(): Stringable|string
    {
        return "You are the Manager sub-agent.\n"
            ."- Convert user intent into scoped execution tasks.\n"
            ."- Assign each task a single owner and clear completion criteria.\n"
            ."- Identify dependencies and execution order.\n"
            ."- Surface risks and missing inputs early.\n"
            .'- Return concise, actionable task plans only.';
    }
}
