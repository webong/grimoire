<?php

namespace Webong\LaravelGouge\Ai\Support;

use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\FileSearch;
use Webong\LaravelGouge\Ai\Support\A2a\A2aRegistry;
use Webong\LaravelGouge\Ai\Support\Acp\AcpRegistry;
use Webong\LaravelGouge\Ai\Support\Knowledge\KnowledgeSearchStoreResolver;
use Webong\LaravelGouge\Ai\Support\Mcp\McpRegistry;
use Webong\LaravelGouge\Ai\Tools\AutoModeSelectorTool;
use Webong\LaravelGouge\Ai\Tools\ContextBudgetTool;
use Webong\LaravelGouge\Ai\Tools\ConversationAuditTool;
use Webong\LaravelGouge\Ai\Tools\CreatePostFromConversationTool;
use Webong\LaravelGouge\Ai\Tools\DelegateA2aTaskTool;
use Webong\LaravelGouge\Ai\Tools\DelegateAcpTaskTool;
use Webong\LaravelGouge\Ai\Tools\DiscoverSkillsTool;
use Webong\LaravelGouge\Ai\Tools\DualWriteDiffTool;
use Webong\LaravelGouge\Ai\Tools\GetSubAgentInvocationContextTool;
use Webong\LaravelGouge\Ai\Tools\InvokeA2aAgentTool;
use Webong\LaravelGouge\Ai\Tools\InvokeAcpAgentTool;
use Webong\LaravelGouge\Ai\Tools\InvokeMcpTool;
use Webong\LaravelGouge\Ai\Tools\InvokeSubAgentTool;
use Webong\LaravelGouge\Ai\Tools\ListAvailableA2aAgentsTool;
use Webong\LaravelGouge\Ai\Tools\ListAvailableAcpAgentsTool;
use Webong\LaravelGouge\Ai\Tools\ListAvailableMcpToolsTool;
use Webong\LaravelGouge\Ai\Tools\ListAvailableSubAgentsTool;
use Webong\LaravelGouge\Ai\Tools\ModePolicyTool;
use Webong\LaravelGouge\Ai\Tools\PrivacyGuardTool;
use Webong\LaravelGouge\Ai\Tools\ReplayTool;
use Webong\LaravelGouge\Ai\Tools\SessionForkTool;
use Webong\LaravelGouge\Ai\Tools\SessionHealthTool;
use Webong\LaravelGouge\Ai\Tools\SessionMergeSummaryTool;
use Webong\LaravelGouge\Ai\Tools\SessionResetTool;
use Webong\LaravelGouge\Ai\Tools\SessionTransferTool;
use Webong\LaravelGouge\Ai\Tools\WriteMemoryFileTool;

class ToolResolver
{
    public function __construct(
        protected KnowledgeSearchStoreResolver $knowledgeSearchStoreResolver = new KnowledgeSearchStoreResolver,
        protected AcpRegistry $acpRegistry = new AcpRegistry,
        protected A2aRegistry $a2aRegistry = new A2aRegistry,
        protected McpRegistry $mcpRegistry = new McpRegistry,
    ) {}

    /**
     * @return list<Tool>
     */
    public function resolve(Thread $thread, User $user, ?ThreadActor $threadActor = null): array
    {
        $sharedTools = $this->sharedTools($thread, $user, $threadActor);
        $threadable = $thread->threadable;

        if ($threadable instanceof Space) {
            $sharedTools[] = new CreatePostFromConversationTool($thread, $threadable, $user);
        }

        return $sharedTools;
    }

    /**
     * @return list<Tool>
     */
    protected function sharedTools(Thread $thread, User $user, ?ThreadActor $threadActor = null): array
    {
        return [new AutoModeSelectorTool($thread, $user),
            new ConversationAuditTool($thread, $user),
            new SessionResetTool($thread, $user),
            new SessionForkTool($thread, $user),
            new SessionTransferTool($thread, $user),
            new SessionMergeSummaryTool($thread, $user),
            new ModePolicyTool($thread, $user),
            new ContextBudgetTool($thread, $user),
            new DiscoverSkillsTool,
            new GetSubAgentInvocationContextTool($thread, $user, $threadActor),
            new ListAvailableSubAgentsTool,
            new InvokeSubAgentTool($thread, $user, $threadActor),
            ...$this->mcpTools($thread, $user, $threadActor),
            ...$this->acpTools($thread, $user, $threadActor),
            ...$this->a2aTools($thread, $user, $threadActor),
            new DualWriteDiffTool($thread, $user),
            new ReplayTool($thread, $user),
            new PrivacyGuardTool($thread, $user),
            new SessionHealthTool($thread, $user),
            new WriteMemoryFileTool($thread, $user),
            ...$this->knowledgeSearchTools($thread),
        ];
    }

    /**
     * @return list<Tool>
     */
    protected function knowledgeSearchTools(Thread $thread): array
    {
        $storeIds = $this->knowledgeSearchStoreResolver->resolveExternalStoreIds($thread);

        if ($storeIds === []) {
            return [];
        }

        return [
            new FileSearch(stores: $storeIds),
        ];
    }

    /**
     * @return list<Tool>
     */
    protected function mcpTools(Thread $thread, User $user, ?ThreadActor $threadActor = null): array
    {
        if (! $this->mcpRegistry->enabled($thread, $user)) {
            return [];
        }

        return [
            new ListAvailableMcpToolsTool($thread, $user),
            new InvokeMcpTool($thread, $user, $threadActor),
        ];
    }

    /**
     * @return list<Tool>
     */
    protected function acpTools(Thread $thread, User $user, ?ThreadActor $threadActor = null): array
    {
        if (! $this->acpRegistry->enabled($thread, $user)) {
            return [];
        }

        return [
            new ListAvailableAcpAgentsTool($thread, $user),
            new InvokeAcpAgentTool($thread, $user, $threadActor),
            new DelegateAcpTaskTool($thread, $user, $threadActor),
        ];
    }

    /**
     * @return list<Tool>
     */
    protected function a2aTools(Thread $thread, User $user, ?ThreadActor $threadActor = null): array
    {
        if (! $this->a2aRegistry->enabled($thread, $user)) {
            return [];
        }

        return [
            new ListAvailableA2aAgentsTool($thread, $user),
            new InvokeA2aAgentTool($thread, $user, $threadActor),
            new DelegateA2aTaskTool($thread, $user, $threadActor),
        ];
    }
}
