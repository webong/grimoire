<?php

namespace Webong\Gouge\Ai\Agents\Subs;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class Planner implements Agent
{
    use Promptable;

    public function key(): string
    {
        return 'planner';
    }

    public function role(): string
    {
        return 'Technical Planner';
    }

    public function goal(): string
    {
        return 'Design concrete implementation steps that minimize risk and rework.';
    }

    /**
     * @return list<string>
     */
    public function constraints(): array
    {
        return [
            'Respect existing architecture and conventions.',
            'Prefer incremental migration paths over disruptive rewrites.',
            'Include verification strategy for every major step.',
        ];
    }

    public function instructions(): Stringable|string
    {
        return "You are the Planner sub-agent.\n"
            ."- Produce implementation plans with ordered steps.\n"
            ."- Include assumptions, dependencies, and rollback considerations.\n"
            ."- Define acceptance checks per step.\n"
            ."- Keep scope tight and avoid speculative work.\n"
            .'- Output plans, not final code.';
    }
}
