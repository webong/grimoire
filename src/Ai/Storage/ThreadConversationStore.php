<?php

namespace Webong\LaravelGouge\Ai\Storage;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;

class ThreadConversationStore implements ConversationStore
{
    public function __construct(
        protected ConversationPersistenceResolver $resolver,
        protected ?string $requestedMode = null,
    ) {}

    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        return $this->resolver->primary($this->requestedMode)->latestConversationId($participantType, $participantId);
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        return $this->resolver->primary($this->requestedMode)->storeConversation($participantType, $participantId, $title);
    }

    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt
    ): string {
        $messageId = $this->resolver->primary($this->requestedMode)
            ->storeUserMessage($conversationId, $participantType, $participantId, $prompt);

        $secondary = $this->resolver->secondary($this->requestedMode);

        if ($secondary !== null) {
            $secondary->storeUserMessage($conversationId, $participantType, $participantId, $prompt);
        }

        return $messageId;
    }

    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response
    ): ?string {
        $messageId = $this->resolver->primary($this->requestedMode)
            ->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response);

        $secondary = $this->resolver->secondary($this->requestedMode);

        if ($secondary !== null) {
            $secondary->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response);
        }

        return $messageId;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->resolver->primary($this->requestedMode)->getLatestConversationMessages($conversationId, $limit);
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
        $this->resolver->primary($this->requestedMode)
            ->storeApprovalResults($conversationId, $participantType, $participantId, $toolResults);

        $secondary = $this->resolver->secondary($this->requestedMode);

        if ($secondary !== null) {
            $secondary->storeApprovalResults($conversationId, $participantType, $participantId, $toolResults);
        }
    }
}
