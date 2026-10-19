<?php

namespace Webong\Gouge\Ai\Gateways\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Webong\Gouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Guide an agent to inspect a space and propose the next work plan.')]
class PlanSpaceWorkPrompt extends Prompt
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $spaceId = trim((string) $request->get('space_id'));
        $objective = trim((string) $request->get('objective', 'Determine the next best work steps for this space.'));
        abort_if($spaceId === '', 422, 'space_id is required.');

        $actor = $payloads->actor($request);
        $space = $payloads->resolveSpace($actor, $spaceId);

        return Response::text(implode("\n", [
            "You are planning work inside Figurate space {$space->uuid}.",
            "Objective: {$objective}",
            '',
            'Use this workflow:',
            '1. Read the space context and active threads.',
            '2. Identify the current workstream, missing information, and blockers.',
            '3. Search conversation context for prior decisions before proposing any action.',
            '4. If a new workstream is needed, create a thread.',
            '5. If a durable summary or decision should be recorded, create a post.',
            '',
            'Output format:',
            '- Current state',
            '- Missing information',
            '- Recommended next actions',
            '- Whether a new thread or post should be created',
        ]));
    }

    public function arguments(): array
    {
        return [
            new Argument('space_id', 'The space UUID to inspect.', true),
            new Argument('objective', 'The planning objective or question to answer.', false),
        ];
    }
}
