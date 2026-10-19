<?php

namespace Webong\Gouge\Agents;

use App\Models\Server\Channel;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;
use Webong\Gouge\Ai\Concerns\RemembersThreadConversations;
use Webong\Gouge\Ai\Middleware\Rules\ApplyResponseRules;
use Webong\Gouge\Ai\Middleware\Rules\ApplySafetyAndPolicyRules;
use Webong\Gouge\Ai\Middleware\Rules\EnforceActorPermissions;
use Webong\Gouge\Ai\Middleware\Rules\EnforceThreadParticipation;
use Webong\Gouge\Ai\Middleware\Rules\EnforceToolBudgetAndTimeouts;
use Webong\Gouge\Ai\Middleware\Rules\PreventDuplicateProcessing;
use Webong\Gouge\Ai\Middleware\Rules\RequireEvidenceForDecisions;
use Webong\Gouge\Ai\Middleware\Rules\ResponseQualityGate;
use Webong\Gouge\Ai\Middleware\Rules\ValidateInputContract;
use Webong\Gouge\Ai\Middleware\Workflows\ComposeAndRouteResponse;
use Webong\Gouge\Ai\Middleware\Workflows\ExecuteToolsAndActions;
use Webong\Gouge\Ai\Middleware\Workflows\InitializeConversationContext;
use Webong\Gouge\Ai\Middleware\Workflows\PlanWorkflowSteps;
use Webong\Gouge\Ai\Middleware\Workflows\PostResponseLearning;
use Webong\Gouge\Ai\Middleware\Workflows\ResolveAudienceContext;
use Webong\Gouge\Ai\Middleware\Workflows\SelectPresenters;
use Webong\Gouge\Ai\Middleware\Workflows\UseThreadConversationStore;
use Webong\Gouge\Ai\Support\ToolResolver;
use Webong\Gouge\Support\AgentActors;
use Webong\Gouge\Support\LaravelAiModelProviderResolver;

class LaravelAiPresenterAgent implements Agent, HasMiddleware, HasTools, RemembersConversations
{
    use Promptable, RemembersThreadConversations;

    public function __construct(
        public ?Thread $thread = null,
        public ?User $actor = null,
        protected ?string $presenterActorKey = null,
        protected ?string $providerChannelId = null,
    ) {}

    public function setPresenterActorKey(?string $actorKey): static
    {
        $this->presenterActorKey = is_string($actorKey) && $actorKey !== ''
            ? $actorKey
            : null;

        return $this;
    }

    public function presenterActorKey(): ?string
    {
        return $this->presenterActorKey;
    }

    public function setProviderChannelId(?string $channelId): static
    {
        $this->providerChannelId = is_string($channelId) && trim($channelId) !== ''
            ? trim($channelId)
            : null;

        return $this;
    }

    public function provider(): ?string
    {
        $channel = $this->providerChannel();

        return $channel instanceof Channel
            ? app(LaravelAiModelProviderResolver::class)->runtimeProviderName($channel)
            : null;
    }

    public function model(): ?string
    {
        $channel = $this->providerChannel();
        $model = data_get($channel?->config, 'model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return "You are an Agent for conversation orchestration.\n"
            ."Operating mode:\n"
            ."- Use tools first to inspect flow/state before committing decisions.\n"
            ."- When process guidance is unclear, call skills tools to find relevant local skills.\n"
            ."- Use knowledge retrieval (RAG / file search) whenever facts are document-backed.\n"
            ."- Keep responses concise, operational, and evidence-aware.\n"
            .'- When state-changing actions are needed, call the appropriate tool instead of free-text promises.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        if (! $this->thread || ! $this->actor) {
            return [];
        }

        return app(ToolResolver::class)->resolve($this->thread, $this->actor, $this->resolveThreadActor());
    }

    public function middleware(): array
    {
        return [
            new UseThreadConversationStore,
            new InitializeConversationContext,
            new ResolveAudienceContext,
            new SelectPresenters,
            new PlanWorkflowSteps,
            new ExecuteToolsAndActions,
            new ComposeAndRouteResponse,
            new PostResponseLearning,
            new EnforceActorPermissions,
            new EnforceThreadParticipation,
            new PreventDuplicateProcessing,
            new ValidateInputContract,
            new ApplySafetyAndPolicyRules,
            new EnforceToolBudgetAndTimeouts,
            new RequireEvidenceForDecisions,
            new ResponseQualityGate,
            new ApplyResponseRules,
        ];
    }

    protected function resolveThreadActor(): ?ThreadActor
    {
        if (! $this->thread || ! is_string($this->presenterActorKey) || trim($this->presenterActorKey) === '') {
            return null;
        }

        return ThreadActor::query()
            ->where('thread_id', $this->thread->id)
            ->where('actorable_type', trim($this->presenterActorKey))
            ->where('role', AgentActors::RolePresenter)
            ->where('status', ThreadActor::StatusActive)
            ->orderBy('priority')
            ->first();
    }

    protected function providerChannel(): ?Channel
    {
        if (! is_string($this->providerChannelId) || trim($this->providerChannelId) === '') {
            return null;
        }

        return Channel::query()
            ->where('uuid', trim($this->providerChannelId))
            ->firstOrFail();
    }
}
