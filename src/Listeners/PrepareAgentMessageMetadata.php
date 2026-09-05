<?php

namespace Webong\LaravelGouge\Listeners;

use App\Events\Server\Chat\PreparingThreadMessage;
use App\Models\Server\User;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Features\Actions\Chat\ResolveActiveThreadPresenters;
use Webong\LaravelGouge\Support\Orchestrate\ResolveObserverDispatchPolicy;

class PrepareAgentMessageMetadata
{
    public function __construct(
        protected ResolveActiveThreadPresenters $resolveActiveThreadPresenters,
        protected ResolveObserverDispatchPolicy $resolveObserverDispatchPolicy,
    ) {}

    public function handle(PreparingThreadMessage $event): void
    {
        if (! $event->entry->actor instanceof User) {
            return;
        }

        $presenters = $this->resolveActiveThreadPresenters->execute($event->entry->thread);
        $observerPolicy = $this->resolveObserverDispatchPolicy->forThread($event->entry->thread);

        if (! array_key_exists('observer_dispatch', $event->meta)) {
            $event->meta['observer_dispatch'] = (bool) $observerPolicy['should_dispatch'];
        }

        if ($presenters->isNotEmpty()) {
            $event->meta['source'] = 'agent_prompt';
        }

        if (! array_key_exists('conversation_persistence', $event->meta)) {
            $conversationPersistence = $this->requestedConversationPersistenceMode();

            if ($conversationPersistence !== null) {
                $event->meta['conversation_persistence'] = $conversationPersistence;
            }
        }
    }

    protected function requestedConversationPersistenceMode(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return ConversationPersistenceResolver::normalizeMode(
            $request?->input('body.attributes.conversation_persistence')
            ?? $request?->header('X-Conversation-Persistence')
        );
    }
}
