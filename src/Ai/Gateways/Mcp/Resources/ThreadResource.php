<?php

namespace Webong\Gouge\Ai\Gateways\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;
use Webong\Gouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Read a Figurate thread by URI template.')]
class ThreadResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://figurate/threads/{threadId}');
    }

    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $actor = $payloads->actor($request);
        $thread = $payloads->resolveThread($actor, (string) $request->get('threadId'));

        return Response::text(json_encode([
            'thread' => $payloads->mapThread($thread),
            'actors' => $thread->actors()
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
                ->map(fn ($threadActor): array => $payloads->mapActor($threadActor))
                ->all(),
            'messages' => $thread->messages()
                ->latest('id')
                ->take(20)
                ->get()
                ->reverse()
                ->values()
                ->map(fn ($message): array => $payloads->mapMessage($message))
                ->all(),
            'posts' => $thread->posts()
                ->latest('id')
                ->take(10)
                ->get()
                ->map(fn ($post): array => $payloads->mapPost($post))
                ->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
