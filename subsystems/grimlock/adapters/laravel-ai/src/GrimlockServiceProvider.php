<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi;

use Grimlock\LaravelAi\Contracts\ClientRegistry;
use Grimlock\LaravelAi\Contracts\ConnectorInvocationPolicy;
use Grimlock\LaravelAi\Contracts\ConnectorRegistry;
use Grimlock\LaravelAi\Contracts\DeviceRegistry;
use Grimlock\LaravelAi\Contracts\SkillCatalog;
use Grimlock\LaravelAi\Support\DefaultConnectorInvocationPolicy;
use Grimlock\LaravelAi\Support\EloquentRegistry;
use Illuminate\Support\ServiceProvider;

final class GrimlockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EloquentRegistry::class);
        $this->app->singleton(SkillCatalog::class, EloquentRegistry::class);
        $this->app->singleton(ClientRegistry::class, EloquentRegistry::class);
        $this->app->singleton(DeviceRegistry::class, EloquentRegistry::class);
        $this->app->singleton(ConnectorRegistry::class, EloquentRegistry::class);
        $this->app->singleton(ConnectorInvocationPolicy::class, DefaultConnectorInvocationPolicy::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
