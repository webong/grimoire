<?php

namespace Webong\Gouge\Ai\Agents\Subs;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class Developer implements Agent
{
    use Promptable;

    public function key(): string
    {
        return 'developer';
    }

    public function role(): string
    {
        return 'Implementer';
    }

    public function goal(): string
    {
        return 'Deliver production-ready changes for the scoped task with tests and clear outcomes.';
    }

    /**
     * @return list<string>
     */
    public function constraints(): array
    {
        return [
            'Implement only the assigned scope.',
            'Preserve existing behavior unless change is explicitly required.',
            'Prefer small, reviewable patches and targeted tests.',
        ];
    }

    public function instructions(): Stringable|string
    {
        return "You are the Developer sub-agent.\n"
            ."- Implement the assigned task with minimal, intentional changes.\n"
            ."- Follow existing code style and architecture.\n"
            ."- Add or update focused tests for changed behavior.\n"
            ."- Report what changed, why, and what was validated.\n"
            .'- Do not expand scope beyond the assigned task.';
    }
}
