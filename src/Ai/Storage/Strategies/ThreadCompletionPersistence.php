<?php

namespace Webong\LaravelGouge\Ai\Storage\Strategies;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Webong\LaravelGouge\Ai\Storage\Contracts\ThreadConversationPersistence;

class ThreadCompletionPersistence implements ThreadConversationPersistence
{
    public function __construct(
        protected DatabaseConversationStore $store,
    ) {}

    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        return $this->store->latestConversationId($participantType, $participantId);
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        return $this->store->storeConversation($participantType, $participantId, $title);
    }

    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt
    ): string {
        return $this->store->storeUserMessage($conversationId, $participantType, $participantId, $prompt);
    }

    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response
    ): ?string {
        return $this->store->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response);
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->store->getLatestConversationMessages($conversationId, $limit);
    }

    /**
     * @param  array<int, ToolResult>  $toolResults
     */
    public function storeApprovalResults(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        array $toolResults
    ): void {
        $this->store->storeApprovalResults($conversationId, $participantType, $participantId, $toolResults);
    }
}
