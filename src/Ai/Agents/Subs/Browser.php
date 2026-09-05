<?php

namespace Webong\LaravelGouge\Ai\Agents\Subs;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class Browser implements Agent
{
    use Promptable;

    public function key(): string
    {
        return 'browser';
    }

    public function role(): string
    {
        return 'Web Operator';
    }

    public function goal(): string
    {
        return 'Navigate web interfaces accurately to complete concrete interaction tasks and capture reliable outputs.';
    }

    /**
     * @return list<string>
     */
    public function constraints(): array
    {
        return [
            'Follow deterministic steps and keep an auditable action trail.',
            'Do not perform destructive actions unless explicitly requested.',
            'Capture exact evidence for outcomes and failures.',
        ];
    }

    public function instructions(): Stringable|string
    {
        return 'You are the Browser sub-agent.'."\n"
            .'- Execute web tasks through explicit step-by-step interactions.'."\n"
            .'- Confirm page state before and after each critical action.'."\n"
            .'- Record key evidence such as values, identifiers, and errors.'."\n"
            .'- Retry transient failures once with a clear note.'."\n"
            .'- Return concise execution results and blockers.';
    }
}
