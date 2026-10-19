<?php

namespace Webong\Gouge\Ai\Support;

use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\FileSearch;
use Webong\Gouge\Ai\Support\A2a\A2aRegistry;
use Webong\Gouge\Ai\Support\Acp\AcpRegistry;
use Webong\Gouge\Ai\Support\Knowledge\KnowledgeSearchStoreResolver;
use Webong\Gouge\Ai\Support\Mcp\McpRegistry;
use Webong\Gouge\Ai\Tools\AutoModeSelectorTool;
use Webong\Gouge\Ai\Tools\ContextBudgetTool;
use Webong\Gouge\Ai\Tools\ConversationAuditTool;
use Webong\Gouge\Ai\Tools\CreatePostFromConversationTool;
use Webong\Gouge\Ai\Tools\DelegateA2aTaskTool;
use Webong\Gouge\Ai\Tools\DelegateAcpTaskTool;
use Webong\Gouge\Ai\Tools\DiscoverSkillsTool;
use Webong\Gouge\Ai\Tools\DualWriteDiffTool;
use Webong\Gouge\Ai\Tools\GetSubAgentInvocationContextTool;
use Webong\Gouge\Ai\Tools\InvokeA2aAgentTool;
use Webong\Gouge\Ai\Tools\InvokeAcpAgentTool;
use Webong\Gouge\Ai\Tools\InvokeMcpTool;
use Webong\Gouge\Ai\Tools\InvokeSubAgentTool;
use Webong\Gouge\Ai\Tools\ListAvailableA2aAgentsTool;
use Webong\Gouge\Ai\Tools\ListAvailableAcpAgentsTool;
use Webong\Gouge\Ai\Tools\ListAvailableMcpToolsTool;
use Webong\Gouge\Ai\Tools\ListAvailableSubAgentsTool;
use Webong\Gouge\Ai\Tools\ModePolicyTool;
use Webong\Gouge\Ai\Tools\PrivacyGuardTool;
use Webong\Gouge\Ai\Tools\ReplayTool;
use Webong\Gouge\Ai\Tools\SessionForkTool;
use Webong\Gouge\Ai\Tools\SessionHealthTool;
use Webong\Gouge\Ai\Tools\SessionMergeSummaryTool;
use Webong\Gouge\Ai\Tools\SessionResetTool;
use Webong\Gouge\Ai\Tools\SessionTransferTool;
use Webong\Gouge\Ai\Tools\WriteMemoryFileTool;

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
