<?php

namespace Webong\Gouge;

use Illuminate\Support\ServiceProvider;
use Webong\Gouge\Contracts\AgentExecutor;
use Webong\Gouge\Contracts\AgentFactory;
use Webong\Gouge\Contracts\AgentRuntime;
use Webong\Gouge\Contracts\RuntimeStore;
use Webong\Gouge\Runtime\ConfiguredAgentFactory;
use Webong\Gouge\Runtime\LaravelAiRuntime;
use Webong\Gouge\Runtime\NativeAgentExecutor;
use Webong\Gouge\Storage\DatabaseRuntimeStore;

class GougeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gouge.php', 'gouge');
        $this->app->bindIf(AgentFactory::class, fn ($app) => new ConfiguredAgentFactory($app, $app['config']->get('gouge.definitions', [])));
        $this->app->bindIf(AgentExecutor::class, fn ($app) => new NativeAgentExecutor(
            $app['config']->get('gouge.provider'), $app['config']->get('gouge.model'), $app['config']->get('gouge.timeout'),
        ));
        $this->app->bindIf(RuntimeStore::class, fn ($app) => new DatabaseRuntimeStore($app['db']->connection($app['config']->get('gouge.connection'))));
        $this->app->bindIf(AgentRuntime::class, LaravelAiRuntime::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
