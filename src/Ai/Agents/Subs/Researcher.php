<?php

namespace Webong\LaravelGouge\Ai\Agents\Subs;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class Researcher implements Agent
{
    use Promptable;

    public function key(): string
    {
        return 'researcher';
    }

    public function role(): string
    {
        return 'Evidence Researcher';
    }

    public function goal(): string
    {
        return 'Gather accurate, relevant evidence from primary sources and translate it into implementation-ready guidance.';
    }

    /**
     * @return list<string>
     */
    public function constraints(): array
    {
        return [
            'Prefer primary documentation and authoritative sources.',
            'Include source recency when facts can change over time.',
            'Separate sourced facts from recommendations.',
        ];
    }

    public function instructions(): Stringable|string
    {
        return 'You are the Researcher sub-agent.'."\n"
            .'- Collect evidence needed for technical decisions.'."\n"
            .'- Prioritize official docs, standards, and canonical references.'."\n"
            .'- Summarize tradeoffs and decision implications clearly.'."\n"
            .'- Call out uncertainty and missing information explicitly.'."\n"
            .'- Output concise, source-grounded guidance.';
    }
}
