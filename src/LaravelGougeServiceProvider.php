<?php

namespace Webong\LaravelGouge;

use App\Events\Server\Api\PreparingResourcePayload;
use App\Events\Server\Auth\WidgetUsersMerging;
use App\Events\Server\Channels\MappingChannelRoute;
use App\Events\Server\Channels\PreparingChannelOutboxPayload;
use App\Events\Server\Channels\PreparingInboundChannelPayload;
use App\Events\Server\Chat\ConversationThreadCreated;
use App\Events\Server\Chat\PreparingMessageResponse;
use App\Events\Server\Chat\PreparingThreadMessage;
use App\Events\Server\Chat\ThreadMessageDispatched;
use App\Events\Server\Notifications\RoutingNotificationChannels;
use App\Features\Actions\Chat\ProtocolRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Nuwave\Lighthouse\Events\BuildSchemaString;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\WebhookProfile\ProcessEverythingWebhookProfile;
use Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Ai\Storage\ThreadConversationStore;
use Webong\LaravelGouge\Contracts\AgentRuntime;
use Webong\LaravelGouge\Features\Actions\Chat\AgentPromptOutboundMessageSender;
use Webong\LaravelGouge\Features\Actions\Chat\Protocols\AgentPromptProtocol;
use Webong\LaravelGouge\Jobs\ProcessInboundA2aPushWebhookJob;
use Webong\LaravelGouge\Listeners\AddAgentContextToChannelOutbox;
use Webong\LaravelGouge\Listeners\AddAgentContextToChannelRoute;
use Webong\LaravelGouge\Listeners\AddAgentContextToInboundChannel;
use Webong\LaravelGouge\Listeners\AddAgentContextToResourcePayload;
use Webong\LaravelGouge\Listeners\ConfigureAgentConversationThread;
use Webong\LaravelGouge\Listeners\DecorateAgentMessageResponse;
use Webong\LaravelGouge\Listeners\MigrateAgentUserData;
use Webong\LaravelGouge\Listeners\PrepareAgentMessage;
use Webong\LaravelGouge\Listeners\PrepareAgentMessageMetadata;
use Webong\LaravelGouge\Listeners\RouteAgentNotification;
use Webong\LaravelGouge\Listeners\Server\Ai\RecordObserverAgentPrompted;
use Webong\LaravelGouge\Listeners\Server\Ai\RecordObserverAgentPrompting;
use Webong\LaravelGouge\Listeners\Server\Chat\QueueThreadObserversForPeerMessage;
use Webong\LaravelGouge\Runtime\LaravelAiRuntime;
use Webong\LaravelGouge\Support\AgentAbilities;
use Webong\LaravelGouge\Support\Channels\Drivers\A2aChannelDriver;
use Webong\LaravelGouge\Support\Channels\Drivers\AcpChannelDriver;
use Webong\LaravelGouge\Support\Channels\Drivers\AiProviderChannelDriver;
use Webong\LaravelGouge\Support\Channels\Drivers\McpChannelDriver;
use Webong\LaravelGouge\Support\Webhooks\A2aPushSignatureValidator;

class LaravelGougeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-gouge.php', 'laravel-gouge');
        $this->mergeConfigFrom(__DIR__.'/../config/a2a.php', 'a2a');
        $this->mergeConfigFrom(__DIR__.'/../config/acp.php', 'acp');
        $this->mergeConfigFrom(__DIR__.'/../config/ai-domain.php', 'ai-domain');
        $this->mergeConfigFrom(__DIR__.'/../config/mcp.php', 'mcp');
        config()->set('token-abilities', array_replace(
            config('token-abilities', []),
            AgentAbilities::descriptions(),
        ));
        config()->set('figurate-auth.robot_default_abilities', array_values(array_unique([
            ...config('figurate-auth.robot_default_abilities', []),
            ...AgentAbilities::defaults(),
        ])));
        config()->set('ai', array_replace_recursive(
            config('ai', []),
            require __DIR__.'/../config/ai.php',
        ));
        config()->set('services.mcp', array_replace_recursive(
            config('services.mcp', []),
            config('laravel-gouge.mcp_client', []),
        ));

        $this->app->singleton(AgentRuntime::class, LaravelAiRuntime::class);
        $this->app->singleton(AgentPromptOutboundMessageSender::class);
        $this->app->singleton(AgentPromptProtocol::class);
        $this->app->tag([AgentPromptProtocol::class], ProtocolRegistry::DriverTag);
        $this->app->singleton(ConversationStore::class, fn ($application): ThreadConversationStore => new ThreadConversationStore(
            resolver: $application->make(ConversationPersistenceResolver::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../routes/api.php');
        Route::middleware('web')
            ->group(__DIR__.'/../routes/web.php');

        Event::listen(ThreadMessageDispatched::class, PrepareAgentMessage::class);
        Event::listen(PreparingThreadMessage::class, PrepareAgentMessageMetadata::class);
        Event::listen(RoutingNotificationChannels::class, RouteAgentNotification::class);
        Event::listen(ConversationThreadCreated::class, ConfigureAgentConversationThread::class);
        Event::listen(PreparingMessageResponse::class, DecorateAgentMessageResponse::class);
        Event::listen(PreparingResourcePayload::class, AddAgentContextToResourcePayload::class);
        Event::listen(ThreadMessageDispatched::class, QueueThreadObserversForPeerMessage::class);
        Event::listen(WidgetUsersMerging::class, MigrateAgentUserData::class);
        Event::listen(PreparingChannelOutboxPayload::class, AddAgentContextToChannelOutbox::class);
        Event::listen(PreparingInboundChannelPayload::class, AddAgentContextToInboundChannel::class);
        Event::listen(MappingChannelRoute::class, AddAgentContextToChannelRoute::class);
        Event::listen(PromptingAgent::class, RecordObserverAgentPrompting::class);
        Event::listen(AgentPrompted::class, RecordObserverAgentPrompted::class);
        Event::listen(BuildSchemaString::class, fn (): string => (string) file_get_contents(
            __DIR__.'/../resources/graphql/schema.graphql',
        ));

        $this->registerChannelDrivers();
        $this->registerA2aWebhook();
    }

    protected function registerChannelDrivers(): void
    {
        config()->set('channels.protocols', array_replace(
            config('channels.protocols', []),
            [
                'mcp' => McpChannelDriver::class,
                'a2a' => A2aChannelDriver::class,
                'acp' => AcpChannelDriver::class,
                'ai_provider' => AiProviderChannelDriver::class,
            ],
        ));
    }

    protected function registerA2aWebhook(): void
    {
        $configs = collect(config('webhook-client.configs', []))
            ->reject(fn (array $config): bool => ($config['name'] ?? null) === 'a2a_push')
            ->values()
            ->all();
        $configs[] = [
            'name' => 'a2a_push',
            'signing_secret' => config('a2a.inbound.push_notifications.inbound_signing_secret'),
            'signature_header_name' => 'Signature',
            'signature_validator' => A2aPushSignatureValidator::class,
            'webhook_profile' => ProcessEverythingWebhookProfile::class,
            'webhook_response' => DefaultRespondsTo::class,
            'webhook_model' => WebhookCall::class,
            'store_headers' => ['*'],
            'process_webhook_job' => ProcessInboundA2aPushWebhookJob::class,
        ];

        config()->set('webhook-client.configs', $configs);
    }
}
