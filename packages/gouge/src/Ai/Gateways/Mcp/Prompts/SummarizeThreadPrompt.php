<?php

namespace Webong\Gouge\Ai\Gateways\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Webong\Gouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Guide an agent to summarize a thread into a durable handoff or status update.')]
class SummarizeThreadPrompt extends Prompt
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $threadId = trim((string) $request->get('thread_id'));
        $summaryType = trim((string) $request->get('summary_type', 'status update'));
        abort_if($threadId === '', 422, 'thread_id is required.');

        $actor = $payloads->actor($request);
        $thread = $payloads->resolveThread($actor, $threadId);

        return Response::text(implode("\n", [
            "Summarize Figurate thread {$thread->uuid} as a {$summaryType}.",
            '',
            'Use the thread messages, posts, and actors to produce:',
            '- Scope of the thread',
            '- Key decisions and unresolved items',
            '- Current owner or active actors',
            '- Recommended next step',
            '',
            'If the result should persist beyond the chat, create a post after writing the summary.',
        ]));
    }

    public function arguments(): array
    {
        return [
            new Argument('thread_id', 'The thread UUID to summarize.', true),
            new Argument('summary_type', 'The kind of summary needed, for example status update or handoff note.', false),
        ];
    }
}
