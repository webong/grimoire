<?php

namespace Webong\LaravelGouge\Ai\Middleware\Workflows;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Ai\Storage\ThreadConversationStore;

class UseThreadConversationStore
{
    public function __construct(protected ?Container $container = null)
    {
        $this->container ??= app();
    }

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $abstract = ConversationStore::class;
        $requestedMode = $this->requestedMode($prompt);
        $hadBinding = $this->container->bound($abstract);
        $bindings = method_exists($this->container, 'getBindings')
            ? $this->container->getBindings()
            : [];
        $originalBinding = $bindings[$abstract] ?? null;
        $hadResolvedInstance = $this->container->resolved($abstract);
        $originalInstance = $hadResolvedInstance ? $this->container->make($abstract) : null;

        $this->container->forgetInstance($abstract);
        $this->container->singleton($abstract, fn (Container $container): ThreadConversationStore => new ThreadConversationStore(
            resolver: $container->make(ConversationPersistenceResolver::class),
            requestedMode: $requestedMode,
        ));

        try {
            return $next($prompt);
        } finally {
            $this->container->forgetInstance($abstract);

            if ($hadBinding && is_array($originalBinding)) {
                $this->container->bind(
                    $abstract,
                    $originalBinding['concrete'],
                    (bool) ($originalBinding['shared'] ?? false),
                );
            }

            if ($hadResolvedInstance && $originalInstance !== null) {
                $this->container->instance($abstract, $originalInstance);
            }
        }
    }

    protected function requestedMode(AgentPrompt $prompt): ?string
    {
        $agentMode = method_exists($prompt->agent, 'conversationMode')
            ? $prompt->agent->conversationMode()
            : null;

        $normalizedAgentMode = ConversationPersistenceResolver::normalizeMode($agentMode);

        if ($normalizedAgentMode !== null) {
            return $normalizedAgentMode;
        }

        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        if (! $request instanceof HttpRequest) {
            return null;
        }

        return ConversationPersistenceResolver::normalizeMode(
            $request->input('conversation_persistence')
            ?? $request->header('X-Conversation-Persistence')
        );
    }
}
