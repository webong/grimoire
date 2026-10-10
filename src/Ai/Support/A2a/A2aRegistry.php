<?php

namespace Webong\Gouge\Ai\Support\A2a;

use App\Models\Server\Channel;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use App\Support\Security\UrlTrustPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Webong\Gouge\Ai\Support\ThreadContextResolver;
use Webong\Gouge\Support\AgentChannels;

class A2aRegistry
{
    public function __construct(
        protected ThreadContextResolver $threadContextResolver = new ThreadContextResolver,
        protected UrlTrustPolicy $urlTrustPolicy = new UrlTrustPolicy,
        protected ChannelLinkRepository $channelLinks = new ChannelLinkRepository,
    ) {}

    public function enabled(?Thread $thread = null, ?User $user = null): bool
    {
        return $this->agents($thread, $user) !== [];
    }

    public function defaultTimeoutSeconds(): int
    {
        $timeout = (int) config('a2a.outbound.default_timeout_seconds', 15);

        return max(3, min(120, $timeout));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function agents(?Thread $thread = null, ?User $user = null): array
    {
        $agentsById = [];

        foreach ($this->credentialCandidates($thread, $user) as $owner) {
            foreach ($this->a2aLinksFor($owner) as $link) {
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
     * @return list<array<string, mixed>>
     */
    public function trustedAgents(?Thread $thread = null, ?User $user = null): array
    {
        return collect($this->agents($thread, $user))
            ->filter(fn (array $agent): bool => ($this->trustDecision($agent)['allowed'] ?? false) === true)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $agent
     * @return array{allowed: bool, reason?: string}
     */
    public function trustDecision(array $agent): array
    {
        $endpoint = $this->stringOrNull($agent['endpoint'] ?? null);

        if ($endpoint === null) {
            return [
                'allowed' => false,
                'reason' => 'Remote A2A agent endpoint URL is missing.',
            ];
        }

        return $this->urlTrustPolicy->authorize(
            $endpoint,
            is_array(config('a2a.outbound.trust')) ? config('a2a.outbound.trust') : [],
        );
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
    protected function a2aLinksFor(Model $owner): Collection
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

                return $channel->driver === AgentChannels::A2a || $protocol === AgentChannels::A2a;
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
            'auth_type' => strtolower((string) ($mergedConfig['auth_type'] ?? $channel->auth_type ?? 'none')),
            'token' => $this->tokenFrom($channel, $mergedConfig),
            'headers' => $this->headersFrom($channel, $mergedConfig),
            'allowed_methods' => $this->normalizeMethods($mergedConfig['allowed_methods'] ?? []),
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
}
