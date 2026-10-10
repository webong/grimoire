<?php

namespace Webong\Gouge\Ai\Concerns;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Laravel\Ai\Concerns\RemembersConversations as LaravelRemembersConversations;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Webong\Gouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\Gouge\Support\AgentActors;

trait RemembersThreadConversations
{
    use LaravelRemembersConversations;

    protected ?string $conversationMode = null;

    public function setConversationMode(?string $mode): static
    {
        $this->conversationMode = ConversationPersistenceResolver::normalizeMode($mode);

        return $this;
    }

    public function conversationMode(): ?string
    {
        return $this->conversationMode;
    }

    public function forParticipant(object $participant): static
    {
        return $this->forUser($participant);
    }

    public function forUser(object $user): static
    {
        $shouldUseThreadConversationIds = app(ConversationPersistenceResolver::class)
            ->shouldUseThreadConversationIds($this->conversationPersistenceModePreference());

        $this->conversationUser = $user;
        $this->conversationId = $shouldUseThreadConversationIds
            ? $this->threadConversationId()
            : null;

        return $this;
    }

    public function continue(string $conversationId, ?object $as = null): static
    {
        $thread = property_exists($this, 'thread') && $this->thread instanceof Thread ? $this->thread : null;
        $threadConversationId = $this->threadConversationId();
        $threadUuid = $thread instanceof Thread
            ? (string) $thread->uuid
            : '';
        $shouldUseThreadConversationIds = app(ConversationPersistenceResolver::class)
            ->shouldUseThreadConversationIds($this->conversationPersistenceModePreference());

        $shouldUseThreadConversationId = $shouldUseThreadConversationIds
            && $threadConversationId !== null
            && $threadUuid !== ''
            && ! str_starts_with($conversationId, $threadUuid.':');

        $this->conversationId = $shouldUseThreadConversationId
            ? $threadConversationId
            : $conversationId;
        $this->conversationUser = $as;

        return $this;
    }

    public function continueLastConversation(object $as): static
    {
        $shouldUseThreadConversationIds = app(ConversationPersistenceResolver::class)
            ->shouldUseThreadConversationIds($this->conversationPersistenceModePreference());

        $this->conversationUser = $as;

        $this->conversationId = ($shouldUseThreadConversationIds ? $this->threadConversationId() : null)
            ?? resolve(ConversationStore::class)->latestConversationId(
                Conversation::participantType($as),
                Conversation::participantKey($as),
            );

        return $this;
    }

    public function messages(): iterable
    {
        if (! $this->conversationId) {
            return [];
        }

        return resolve(ConversationStore::class)
            ->getLatestConversationMessages(
                $this->conversationId,
                $this->maxConversationMessages()
            )->all();
    }

    protected function maxConversationMessages(): int
    {
        return 100;
    }

    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    public function hasConversationParticipant(): bool
    {
        return $this->conversationUser !== null;
    }

    public function conversationParticipant(): ?object
    {
        return $this->conversationUser;
    }

    protected function threadConversationId(): ?string
    {
        $thread = property_exists($this, 'thread') ? $this->thread : null;

        if (! $thread instanceof Thread || ! is_string($thread->uuid) || $thread->uuid === '') {
            return null;
        }

        return $thread->uuid.':'.$this->conversationActorKey();
    }

    protected function conversationActorKey(): string
    {
        if (method_exists($this, 'presenterActorKey')) {
            $actorKey = $this->presenterActorKey();

            if (is_string($actorKey) && $actorKey !== '') {
                return $actorKey;
            }
        }

        if (property_exists($this, 'thread') && $this->thread instanceof Thread) {
            $primaryActorKey = $this->thread->actors()
                ->where('role', AgentActors::RolePresenter)
                ->where('status', ThreadActor::StatusActive)
                ->orderBy('priority')
                ->first()?->actorName();

            if (is_string($primaryActorKey) && $primaryActorKey !== '') {
                return $primaryActorKey;
            }
        }

        return AgentActors::Coordinator;
    }

    protected function conversationPersistenceModePreference(): ?string
    {
        return $this->conversationMode();
    }
}
