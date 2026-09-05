<?php

namespace Webong\LaravelGouge\Ai\Gateways\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Prompts\PlanSpaceWorkPrompt;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Prompts\SummarizeThreadPrompt;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Resources\ComposeServerGuideResource;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Resources\SpaceResource;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Resources\ThreadResource;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\AssignThreadActorTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\CreateGraphEdgeTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\CreatePostTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\CreateThreadTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\ListPostsTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\ListSpacesTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\ListThreadActorsTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\ListThreadsTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\QueryGraphEdgesTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\ReadSpaceTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\ReadThreadTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\SearchConversationContextTool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Tools\TransferThreadSessionTool;

#[Name('Compose Server')]
#[Version('0.0.1')]
#[Instructions('Use the Compose server to inspect and operate on Figurate chat context. It exposes spaces, threads, posts, actors, and context search. Prefer read tools first, then use create_thread or create_post for safe workflow actions. Application-specific state transitions remain outside the server.')]
class ComposeServer extends Server
{
    protected array $tools = [
        ListSpacesTool::class,
        ReadSpaceTool::class,
        ListThreadsTool::class,
        ReadThreadTool::class,
        ListPostsTool::class,
        ListThreadActorsTool::class,
        SearchConversationContextTool::class,
        QueryGraphEdgesTool::class,
        AssignThreadActorTool::class,
        TransferThreadSessionTool::class,
        CreateGraphEdgeTool::class,
        CreateThreadTool::class,
        CreatePostTool::class,
    ];

    protected array $resources = [
        ComposeServerGuideResource::class,
        SpaceResource::class,
        ThreadResource::class,
    ];

    protected array $prompts = [
        PlanSpaceWorkPrompt::class,
        SummarizeThreadPrompt::class,
    ];
}
