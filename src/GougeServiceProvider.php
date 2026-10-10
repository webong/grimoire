<?php

namespace Webong\Gouge;

use App\Coordination\WorkExchange\ParticipantEndpointRegistry;
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
use Webong\Gouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\Gouge\Ai\Storage\ThreadConversationStore;
use Webong\Gouge\Contracts\AgentRuntime;
use Webong\Gouge\Coordination\GougeBridge;
use Webong\Gouge\Jobs\ProcessInboundA2aPushWebhookJob;
use Webong\Gouge\Listeners\AddAgentContextToChannelOutbox;
use Webong\Gouge\Listeners\AddAgentContextToChannelRoute;
use Webong\Gouge\Listeners\AddAgentContextToInboundChannel;
use Webong\Gouge\Listeners\AddAgentContextToResourcePayload;
use Webong\Gouge\Listeners\ConfigureAgentConversationThread;
use Webong\Gouge\Listeners\DecorateAgentMessageResponse;
use Webong\Gouge\Listeners\MigrateAgentUserData;
use Webong\Gouge\Listeners\PrepareAgentMessage;
use Webong\Gouge\Listeners\PrepareAgentMessageMetadata;
use Webong\Gouge\Listeners\RouteAgentNotification;
use Webong\Gouge\Listeners\Server\Ai\RecordObserverAgentPrompted;
use Webong\Gouge\Listeners\Server\Ai\RecordObserverAgentPrompting;
use Webong\Gouge\Listeners\Server\Chat\QueueThreadObserversForPeerMessage;
use Webong\Gouge\Runtime\LaravelAiRuntime;
use Webong\Gouge\Support\AgentAbilities;
use Webong\Gouge\Support\Channels\Drivers\A2aChannelDriver;
use Webong\Gouge\Support\Channels\Drivers\AcpChannelDriver;
use Webong\Gouge\Support\Channels\Drivers\AiProviderChannelDriver;
use Webong\Gouge\Support\Channels\Drivers\McpChannelDriver;
use Webong\Gouge\Support\Webhooks\A2aPushSignatureValidator;

class GougeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gouge.php', 'gouge');
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
            config('gouge.mcp_client', []),
        ));

        $this->app->singleton(AgentRuntime::class, LaravelAiRuntime::class);
        $this->app->singleton(GougeBridge::class);
        $this->app->tag([GougeBridge::class], ParticipantEndpointRegistry::BridgeTag);
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
