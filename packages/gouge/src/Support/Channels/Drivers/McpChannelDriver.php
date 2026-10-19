<?php

namespace Webong\Gouge\Support\Channels\Drivers;

use App\Models\Server\Channel;
use App\Support\Channels\Drivers\GenericChannelDriver;
use App\Support\Security\UrlTrustPolicy;
use Illuminate\Validation\ValidationException;
use Webong\Gouge\Ai\Support\Mcp\McpRemoteEndpointClient;
use Webong\Gouge\Support\AgentChannels;

class McpChannelDriver extends GenericChannelDriver
{
    public function __construct(
        protected McpRemoteEndpointClient $remoteEndpointClient,
        protected UrlTrustPolicy $urlTrustPolicy,
    ) {}

    public function key(): string
    {
        return AgentChannels::Mcp;
    }

    public function supportedTransports(): array
    {
        return [Channel::TransportHttp, Channel::TransportWebsocket, Channel::TransportStdio];
    }

    public function supportedProtocols(): array
    {
        return [AgentChannels::Mcp];
    }

    public function capabilities(?Channel $channel = null): array
    {
        return ['context.read', 'tool.call'];
    }

    public function prepareForRegistration(array $attributes): array
    {
        return $this->prepare(
            normalized: parent::prepareForRegistration($attributes),
            transport: (string) ($attributes['transport'] ?? Channel::TransportHttp),
            mode: (string) ($attributes['mode'] ?? 'remote'),
        );
    }

    public function prepareForUpdate(Channel $channel, array $attributes): array
    {
        $normalized = parent::prepareForUpdate($channel, $attributes);
        $transport = (string) ($normalized['transport'] ?? $channel->transport ?? Channel::TransportHttp);
        $mode = (string) ($normalized['mode'] ?? 'remote');

        return $this->prepare($normalized, $transport, $mode);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    protected function prepare(array $normalized, string $transport, string $mode): array
    {
        $transport = strtolower(trim($transport));
        $mode = strtolower(trim($mode));

        if ($mode === 'local') {
            return $normalized;
        }

        if (! in_array($transport, [Channel::TransportHttp, Channel::TransportWebsocket], true)) {
            return $normalized;
        }

        $endpointUrl = $normalized['endpoint_url'] ?? null;

        if (! is_string($endpointUrl) || trim($endpointUrl) === '') {
            return $normalized;
        }

        $trust = $this->urlTrustPolicy->authorize(
            trim($endpointUrl),
            is_array(config('services.mcp.trust')) ? config('services.mcp.trust') : [],
        );

        if (! ($trust['allowed'] ?? false)) {
            throw ValidationException::withMessages([
                'endpoint_url' => (string) ($trust['reason'] ?? 'Remote endpoint URL is not allowed by policy.'),
            ]);
        }

        $configuredTools = $normalized['allowed_tools'] ?? null;

        if (is_array($configuredTools) && $configuredTools !== []) {
            return $normalized;
        }

        $normalized['allowed_tools'] = $this->remoteEndpointClient->listTools(
            endpointUrl: trim($endpointUrl),
            headers: $this->headersFromCredentials($normalized['credentials'] ?? []),
        );

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    protected function headersFromCredentials(mixed $credentials): array
    {
        if (! is_array($credentials)) {
            return [];
        }

        $headers = $credentials['headers'] ?? [];

        if (! is_array($headers)) {
            return [];
        }

        return collect($headers)
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && trim($key) !== '' && is_string($value))
            ->mapWithKeys(fn (string $value, string $key): array => [trim($key) => $value])
            ->all();
    }
}
