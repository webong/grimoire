<?php

namespace Webong\LaravelGouge\Ai\Support\Acp;

use App\Models\Server\Channel;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Webong\LaravelGouge\Ai\Support\ThreadContextResolver;
use Webong\LaravelGouge\Support\AgentChannels;

class AcpRegistry
{
    public function __construct(
        protected ThreadContextResolver $threadContextResolver = new ThreadContextResolver,
        protected ChannelLinkRepository $channelLinks = new ChannelLinkRepository,
    ) {}

    public function enabled(?Thread $thread = null, ?User $user = null): bool
    {
        return $this->agents($thread, $user) !== [];
    }

    public function defaultTimeoutSeconds(): int
    {
        $timeout = (int) config('acp.outbound.default_timeout_seconds', 20);

        return max(3, min(180, $timeout));
    }

    /**
     * @param  array<string, mixed>  $agent
     * @return array{id: string|null, name: string|null, version: string|null, capabilities: array<string, mixed>}
     */
    public function client(array $agent = []): array
    {
        $client = is_array($agent['client'] ?? null) ? $agent['client'] : [];
        $defaultName = $this->stringOrNull(config('app.name')) ?? 'Figurate';
        $defaultId = Str::of($defaultName)->slug('-')->value();

        return [
            'id' => $this->stringOrNull(data_get($client, 'id')) ?? ($defaultId !== '' ? $defaultId : 'figurate'),
            'name' => $this->stringOrNull(data_get($client, 'name')) ?? $defaultName,
            'version' => $this->stringOrNull(data_get($client, 'version')),
            'capabilities' => is_array(data_get($client, 'capabilities')) ? data_get($client, 'capabilities') : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function agents(?Thread $thread = null, ?User $user = null): array
    {
        $agentsById = [];

        foreach ($this->credentialCandidates($thread, $user) as $owner) {
            foreach ($this->acpLinksFor($owner) as $link) {
                $agent = $this->agentFromLink($link, $owner);

                if (! is_array($agent)) {
                    continue;
                }

                $agentId = $this->stringOrNull($agent['id'] ?? null);

                if ($agentId === null || array_key_exists($agentId, $agentsById)) {
                    continue;
                }

                $agentsById[$agentId] = $agent;
            }
        }

        return array_values($agentsById);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $agentId, ?Thread $thread = null, ?User $user = null): ?array
    {
        $normalizedId = $this->stringOrNull($agentId);

        if ($normalizedId === null) {
            return null;
        }

        return collect($this->agents($thread, $user))
            ->first(fn (array $agent): bool => (string) ($agent['id'] ?? '') === $normalizedId);
    }

    /**
     * @param  array<string, mixed>  $agent
     */
    public function isMethodAllowed(array $agent, string $method): bool
    {
        $allowedMethods = is_array($agent['allowed_methods'] ?? null) ? $agent['allowed_methods'] : [];

        if ($allowedMethods === []) {
            return false;
        }

        return in_array(trim($method), $allowedMethods, true);
    }

    /**
     * @return list<Model>
     */
    protected function credentialCandidates(?Thread $thread, ?User $user): array
    {
        $candidates = [];

        if ($thread instanceof Thread) {
            $candidates[] = $thread;

            $space = $this->threadContextResolver->resolveSpace($thread);
            if ($space instanceof Model) {
                $candidates[] = $space;
            }
        }

        if ($user instanceof User) {
            $candidates[] = $user;
        }

        return $candidates;
    }

    /**
     * @return Collection<int, Post>
     */
    protected function acpLinksFor(Model $owner): Collection
    {
        return $this->channelLinks->forContext($owner)
            ->filter(function (Post $link): bool {
                $channel = $this->channelLinks->channel($link);

                if (! $channel instanceof Channel || ! $channel->enabled) {
                    return false;
                }

                if (! in_array($channel->status, [Channel::StatusActive, null], true)) {
                    return false;
                }

                if (! in_array($this->channelLinks->direction($link), [Channel::DirectionOutbound, Channel::DirectionBidirectional], true)) {
                    return false;
                }

                $linkConfig = $this->channelLinks->config($link);
                $channelConfig = is_array($channel->config) ? $channel->config : [];
                $protocol = $this->stringOrNull($linkConfig['protocol'] ?? $channelConfig['protocol'] ?? null);

                return $channel->driver === AgentChannels::Acp || $protocol === AgentChannels::Acp;
            })
            ->sortByDesc(function (Post $link): int {
                $channel = $this->channelLinks->channel($link);

                return (((int) ($channel?->priority ?? 0)) * 1000000) + (int) $link->id;
            })
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function agentFromLink(Post $link, Model $owner): ?array
    {
        $channel = $this->channelLinks->channel($link);

        if (! $channel instanceof Channel) {
            return null;
        }

        $linkConfig = $this->channelLinks->config($link);
        $channelConfig = is_array($channel->config) ? $channel->config : [];
        $mergedConfig = array_replace_recursive($channelConfig, $linkConfig);
        $endpoint = $this->endpointFrom($channel, $mergedConfig);

        if ($endpoint === null) {
            return null;
        }

        $agentId = $this->stringOrNull(data_get($link->payload, 'data.agent_id'))
            ?? $this->stringOrNull($mergedConfig['agent_id'] ?? null)
            ?? $this->stringOrNull($mergedConfig['gateway_agent'] ?? null)
            ?? $this->stringOrNull($channel->server)
            ?? $this->stringOrNull($channel->name)
            ?? $channel->uuid;

        if ($agentId === null) {
            return null;
        }

        return [
            'id' => $agentId,
            'label' => $this->stringOrNull($mergedConfig['label'] ?? null)
                ?? $this->stringOrNull($channel->label)
                ?? $this->stringOrNull($channel->name),
            'endpoint' => $endpoint,
            'transport' => $this->acpTransportFrom($channel, $mergedConfig),
            'gateway_agent' => $this->stringOrNull($mergedConfig['gateway_agent'] ?? null),
            'auth_type' => strtolower((string) ($mergedConfig['auth_type'] ?? $channel->auth_type ?? 'none')),
            'token' => $this->tokenFrom($channel, $mergedConfig),
            'headers' => $this->headersFrom($channel, $mergedConfig),
            'allowed_methods' => $this->normalizeMethods($mergedConfig['allowed_methods'] ?? []),
            'initialize_payload' => is_array($mergedConfig['initialize_payload'] ?? null) ? $mergedConfig['initialize_payload'] : [],
            'authenticate_payload' => is_array($mergedConfig['authenticate_payload'] ?? null) ? $mergedConfig['authenticate_payload'] : [],
            'session' => $this->normalizeSessionConfig($mergedConfig['session'] ?? []),
            'client' => is_array($mergedConfig['client'] ?? null) ? $mergedConfig['client'] : [],
            'channel_id' => $channel->id,
            'channel_uuid' => $channel->uuid,
            'link_id' => $link->ulid,
            'context_source' => strtolower(class_basename($owner)),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function endpointFrom(Channel $channel, array $config): ?string
    {
        return $this->stringOrNull($config['endpoint_url'] ?? null)
            ?? $this->stringOrNull($channel->endpoint_url);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function tokenFrom(Channel $channel, array $config): ?string
    {
        $credentials = is_array($config['credentials'] ?? null)
            ? $config['credentials']
            : (is_array($channel->credentials) ? $channel->credentials : []);

        return $this->stringOrNull($credentials['token'] ?? null)
            ?? $this->stringOrNull($config['token'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    protected function headersFrom(Channel $channel, array $config): array
    {
        $headers = [];

        $configuredHeaders = is_array($config['headers'] ?? null) ? $config['headers'] : [];
        $credentialHeaders = is_array(data_get($config, 'credentials.headers'))
            ? data_get($config, 'credentials.headers')
            : (is_array(data_get($channel->credentials, 'headers')) ? data_get($channel->credentials, 'headers') : []);

        foreach ([$configuredHeaders, $credentialHeaders] as $headerBag) {
            foreach ($headerBag as $key => $value) {
                if (! is_string($key) || trim($key) === '' || ! is_string($value)) {
                    continue;
                }

                $headers[trim($key)] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function acpTransportFrom(Channel $channel, array $config): string
    {
        $explicitTransport = $this->stringOrNull(data_get($config, 'acp.transport'))
            ?? $this->stringOrNull($config['rpc_transport'] ?? null);

        if ($explicitTransport !== null) {
            return strtolower($explicitTransport);
        }

        if ($this->stringOrNull($config['gateway_agent'] ?? null) !== null) {
            return 'acp-gateway-http';
        }

        $systemTransport = strtolower((string) ($config['transport'] ?? $channel->transport ?? 'http'));

        return match ($systemTransport) {
            'http', 'webhook', 'remote' => 'jsonrpc-http',
            default => $systemTransport,
        };
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    protected function normalizeMethods(mixed $methods): array
    {
        if (! is_array($methods)) {
            return [];
        }

        return collect($methods)
            ->filter(fn (mixed $method): bool => is_string($method) && trim($method) !== '')
            ->map(fn (string $method): string => trim($method))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     reuse: string,
     *     create_method: string,
     *     load_method: string,
     *     prompt_method: string,
     *     id_argument: string,
     *     prompt_argument: string,
     *     prompt_mode: string,
     *     load_after_prompt: bool,
     *     create_params: array<string, mixed>,
     *     load_params: array<string, mixed>,
     *     prompt_params: array<string, mixed>
     * }
     */
    protected function normalizeSessionConfig(mixed $config): array
    {
        $config = is_array($config) ? $config : [];

        return [
            'reuse' => $this->stringOrNull($config['reuse'] ?? null) ?? 'thread',
            'create_method' => $this->stringOrNull($config['create_method'] ?? null) ?? 'session/new',
            'load_method' => $this->stringOrNull($config['load_method'] ?? null) ?? 'session/load',
            'prompt_method' => $this->stringOrNull($config['prompt_method'] ?? null) ?? 'session/prompt',
            'id_argument' => $this->stringOrNull($config['id_argument'] ?? null) ?? 'session_id',
            'prompt_argument' => $this->stringOrNull($config['prompt_argument'] ?? null) ?? 'prompt',
            'prompt_mode' => $this->stringOrNull($config['prompt_mode'] ?? null) ?? 'string',
            'load_after_prompt' => (bool) ($config['load_after_prompt'] ?? true),
            'create_params' => is_array($config['create_params'] ?? null) ? $config['create_params'] : [],
            'load_params' => is_array($config['load_params'] ?? null) ? $config['load_params'] : [],
            'prompt_params' => is_array($config['prompt_params'] ?? null) ? $config['prompt_params'] : [],
        ];
    }
}
