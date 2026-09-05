<?php

namespace Webong\LaravelGouge\Ai\Gateways\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Operational guidance for using the Compose MCP server hosted by Figurate.')]
#[MimeType('text/markdown')]
#[Uri('file://compose/guide')]
class ComposeServerGuideResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text(implode("\n", [
            '# Compose MCP Guide',
            '',
            'Use the Compose server to inspect and operate on space-based work in Figurate.',
            '',
            'Core objects:',
            '- Space: long-lived context and entrypoint.',
            '- Thread: active work session inside a space.',
            '- Post: durable artifact or domain event attached to a space or thread.',
            '- Thread Actor: human or named agent participating in a thread.',
            '',
            'Recommended workflow:',
            '1. Start with `list_spaces` or `read_space`.',
            '2. Inspect active work using `list_threads`, `read_thread`, and `list_posts`.',
            '3. Use `search_conversation_context` before making decisions.',
            '4. Use `create_thread` for a new workstream and `create_post` for durable summaries or notes.',
            '5. Use `assign_thread_actor` or `transfer_thread_session` only when orchestration changes are intentional.',
            '',
            'Scope limits:',
            '- This server does not advance application-specific states.',
            '- Use safe thread and post mutations only.',
        ]));
    }
}
