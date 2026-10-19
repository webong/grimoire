<?php

namespace Webong\Gouge\Http\Controllers\Api\A2a;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Webong\Gouge\Ai\Support\A2ui\A2uiCatalogRegistry;

class AgentCardController extends Controller
{
    public function __construct(
        protected A2uiCatalogRegistry $a2uiCatalogRegistry,
    ) {}

    public function __invoke(): JsonResponse
    {
        $a2uiEnabled = (bool) config('a2a.inbound.a2ui.enabled', false);

        return response()->json([
            'id' => config('a2a.inbound.agent.id'),
            'name' => config('a2a.inbound.agent.name'),
            'description' => config('a2a.inbound.agent.description'),
            'version' => config('a2a.inbound.agent.version'),
            'protocol' => [
                'name' => 'a2a',
                'version' => (string) config('a2a.inbound.protocol.version', 'latest'),
                'transport' => ['jsonrpc', 'sse'],
            ],
            'capabilities' => [
                'streaming' => (bool) config('a2a.inbound.capabilities.streaming', true),
                'push_notifications' => (bool) config('a2a.inbound.capabilities.push_notifications', false),
                'pushNotifications' => (bool) config('a2a.inbound.capabilities.push_notifications', false),
                'history' => (bool) config('a2a.inbound.capabilities.history', true),
            ],
            'endpoints' => [
                'rpc' => '/api/a2a/rpc',
                'stream' => '/api/a2a/stream',
                'tasks' => '/api/a2a/rpc',
            ],
            'extensions' => $a2uiEnabled ? [[
                'uri' => (string) config('a2a.inbound.a2ui.uri', 'https://a2ui.org/specification/v0.8-a2ui/'),
                'description' => 'A2UI extension support for UI data parts and user-action transport.',
                'required' => (bool) config('a2a.inbound.a2ui.required', false),
                'params' => [
                    'mimeType' => 'application/json+a2ui',
                    'supportedCatalogIds' => $this->a2uiCatalogRegistry->supportedCatalogIds(),
                    'acceptsInlineCatalogs' => (bool) config('a2a.inbound.a2ui.catalogs.accepts_inline', true),
                ],
            ]] : [],
            'security' => [
                'mode' => 'scaffold',
            ],
        ]);
    }
}
