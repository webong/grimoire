<?php

namespace Webong\LaravelGouge\Ai\Storage\Contracts;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;

interface ThreadConversationPersistence
{
    public function latestConversationId(string $participantType, string|int $participantId): ?string;

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string;

    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt
    ): string;

    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response
    ): ?string;

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection;

    /**
     * @param  array<int, ToolResult>  $toolResults
     */
    public function storeApprovalResults(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        array $toolResults
    ): void;
}
