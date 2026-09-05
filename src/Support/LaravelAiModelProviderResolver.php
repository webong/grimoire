<?php

namespace Webong\LaravelGouge\Support;

use App\Models\Server\Channel;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;

class LaravelAiModelProviderResolver
{
    /**
     * @return list<string>
     */
    public static function supportedProviderDrivers(): array
    {
        $providers = config('laravel-gouge.model_providers', []);

        return is_array($providers) ? array_values(array_map('strval', array_keys($providers))) : [];
    }

    public function runtimeProviderName(Channel $channel): string
    {
        $runtimeName = $this->runtimeName($channel);
        $manager = app(AiManager::class);
        $providerDriver = $this->providerDriver($channel);
        $providerDefinition = $this->providerDefinition($providerDriver);
        $providerConfig = $this->providerConfig($channel, $providerDriver);
        $factory = $providerDefinition['factory'];

        $manager->extend($runtimeName, function () use ($manager, $providerDriver, $providerConfig, $factory): TextProvider {
            if (! method_exists($manager, $factory)) {
                throw new InvalidArgumentException("AI provider factory [{$factory}] for [{$providerDriver}] is not available.");
            }

            $provider = $manager->{$factory}($providerConfig);

            if (! $provider instanceof TextProvider) {
                throw new InvalidArgumentException("AI provider [{$providerDriver}] does not support text generation.");
            }

            return $provider;
        });

        return $runtimeName;
    }

    /**
     * @return array<string, string>
     */
    public function publicConfiguration(Channel $channel): array
    {
        $config = is_array($channel->config) ? $channel->config : [];

        return array_filter([
            'provider' => $config['provider'] ?? null,
            'model' => $config['model'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function providerDriver(Channel $channel): string
    {
        $provider = strtolower(trim((string) data_get(
            $channel->config,
            'provider',
            config('laravel-gouge.channel.default_provider'),
        )));

        if (! in_array($provider, self::supportedProviderDrivers(), true)) {
            throw new InvalidArgumentException("AI provider driver [{$provider}] is not supported.");
        }

        return $provider;
    }

    /**
     * @return array{factory: string}
     */
    protected function providerDefinition(string $provider): array
    {
        $definition = config("laravel-gouge.model_providers.{$provider}");

        if (! is_array($definition) || ! is_string($definition['factory'] ?? null)) {
            throw new InvalidArgumentException("AI provider [{$provider}] is not configured.");
        }

        return ['factory' => $definition['factory']];
    }

    protected function runtimeName(Channel $channel): string
    {
        $version = $channel->updated_at?->getPreciseTimestamp() ?? 0;

        return 'figurate-channel-'.Str::slug((string) $channel->uuid).'-'.$version;
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerConfig(Channel $channel, string $providerDriver): array
    {
        $channelConfig = is_array($channel->config) ? $channel->config : [];
        $credentials = is_array($channel->credentials) ? $channel->credentials : [];
        $model = trim((string) ($channelConfig['model'] ?? ''));
        $key = $credentials['token'] ?? $credentials['api_key'] ?? null;
        $providerConfig = Arr::except($channelConfig, ['provider', 'model', 'key', 'api_key']);

        $providerConfig['driver'] = $providerDriver;
        $providerConfig['key'] = is_string($key) ? $key : null;
        $providerConfig['name'] = $channel->uuid;
        $providerConfig['url'] = $channel->endpoint_url ?: ($providerConfig['url'] ?? null);
        $providerConfig['models'] = array_replace_recursive(
            is_array($providerConfig['models'] ?? null) ? $providerConfig['models'] : [],
            [
                'text' => [
                    'default' => $model,
                    'cheapest' => $model,
                    'smartest' => $model,
                ],
            ],
        );

        return $providerConfig;
    }
}
