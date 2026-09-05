<?php

namespace Webong\LaravelGouge\Support\Channels\Drivers;

use App\Models\Server\Channel;
use App\Support\Channels\Drivers\GenericChannelDriver;
use Webong\LaravelGouge\Support\AgentChannels;

class AiProviderChannelDriver extends GenericChannelDriver
{
    public function key(): string
    {
        return AgentChannels::AiProvider;
    }

    public function supportedTransports(): array
    {
        $transports = config('laravel-gouge.channel.transports', [Channel::TransportHttp]);

        return is_array($transports) ? array_values($transports) : [Channel::TransportHttp];
    }

    public function supportedProtocols(): array
    {
        return [AgentChannels::AiProvider];
    }

    public function capabilities(?Channel $channel = null): array
    {
        $capabilities = config('laravel-gouge.channel.capabilities', []);

        return is_array($capabilities) ? array_values($capabilities) : [];
    }

    public function prepareForRegistration(array $attributes): array
    {
        $prepared = parent::prepareForRegistration($attributes);
        $prepared['transport'] = config('laravel-gouge.channel.transport', Channel::TransportHttp);
        $prepared['config'] = $this->normalizeProviderConfig($prepared['config'] ?? []);

        return $prepared;
    }

    public function prepareForUpdate(Channel $channel, array $attributes): array
    {
        $prepared = parent::prepareForUpdate($channel, $attributes);

        if (array_key_exists('config', $prepared) && is_array($prepared['config'])) {
            $prepared['config'] = $this->normalizeProviderConfig(array_merge(
                is_array($channel->config) ? $channel->config : [],
                $prepared['config'],
            ));
        }

        if (array_key_exists('transport', $prepared)) {
            $prepared['transport'] = config('laravel-gouge.channel.transport', Channel::TransportHttp);
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function normalizeProviderConfig(array $config): array
    {
        $provider = strtolower(trim((string) ($config['provider'] ?? '')));
        $model = trim((string) ($config['model'] ?? ''));

        if ($provider !== '') {
            $config['provider'] = $provider;
        }

        if ($model !== '') {
            $config['model'] = $model;
        }

        return $config;
    }
}
