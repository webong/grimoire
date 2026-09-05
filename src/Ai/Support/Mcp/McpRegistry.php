<?php

namespace Webong\LaravelGouge\Ai\Support\Mcp;

use App\Models\Server\Channel;
use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\User;
use App\Support\Channels\ChannelLinkRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Webong\LaravelGouge\Ai\Support\ThreadContextResolver;
use Webong\LaravelGouge\Support\AgentChannels;

class McpRegistry
{
    public function __construct(
        protected ThreadContextResolver $threadContextResolver = new ThreadContextResolver,
        protected ChannelLinkRepository $channelLinks = new ChannelLinkRepository,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     server: string,
     *     transport: string,
     *     mode: ?string,
     *     endpoint_url: ?string,
     *     handler: ?string,
     *     config: array<string, mixed>,
     *     default_timeout_ms: int,
     *     max_timeout_ms: int,
     *     tools: list<string>,
     *     headers: array<string, string>,
     *     context_source: ?string,
     *     context_server_id: ?int
     * }
     */
    public function resolve(string $server, ?Thread $thread = null, ?User $user = null): array
    {
        $defaultTimeout = $this->defaultTimeout();
        $maxTimeout = $this->maxTimeout();

        $resolved = [
            'enabled' => false,
            'server' => $server,
            'transport' => 'http',
            'mode' => 'remote',
            'endpoint_url' => null,
            'handler' => $this->stringValue(config('services.mcp.default_handler')),
            'config' => [],
            'default_timeout_ms' => $defaultTimeout,
            'max_timeout_ms' => $maxTimeout,
            'tools' => [],
            'headers' => [],
            'context_source' => null,
            'context_server_id' => null,
        ];

        $contextServer = $this->resolveContextServer($server, $thread, $user);

        if (! $contextServer) {
            return $resolved;
        }

        $resolved['enabled'] = true;

        $contextLink = $this->resolveContextLink($contextServer);
        $resolved['context_server_id'] = $contextServer->id;
        $resolved['context_source'] = $this->contextSource($contextServer);

        $contextMeta = is_array($contextServer->meta) ? $contextServer->meta : [];
        $linkConfig = $contextLink instanceof Post ? $this->channelLinks->config($contextLink) : [];
        $transport = $this->stringValue($linkConfig['transport'] ?? $contextServer->transport ?? null);
        if ($transport !== null) {
            $resolved['transport'] = strtolower($transport);
        }

        $mode = $this->stringValue($linkConfig['mode'] ?? $contextMeta['mode'] ?? null);
        if ($mode !== null) {
            $resolved['mode'] = strtolower($mode);
        }

        $endpointUrl = $this->stringValue($linkConfig['endpoint_url'] ?? $contextServer->endpoint_url ?? null);
        if ($endpointUrl !== null) {
            $resolved['endpoint_url'] = $endpointUrl;
        }

        $handler = $this->stringValue($linkConfig['handler'] ?? $contextMeta['handler'] ?? $contextServer->handler ?? null);
        if ($handler !== null) {
            $resolved['handler'] = $handler;
        }

        $mergedConfig = $linkConfig !== []
            ? $linkConfig
            : (is_array($contextServer->config ?? null) ? $contextServer->config : []);
        if ($mergedConfig !== []) {
            $resolved['config'] = array_merge($resolved['config'], $mergedConfig);
        }

        $allowedTools = $this->normalizeStringList($contextServer->allowed_tools ?? []);
        if ($allowedTools !== []) {
            $resolved['tools'] = $allowedTools;
        }

        $resolved['headers'] = array_merge(
            $resolved['headers'],
            $this->headersFromContextServer($contextServer),
        );

        return $resolved;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function available(?Thread $thread = null, ?User $user = null): array
    {
        $serverNames = collect();

        foreach ($this->credentialCandidates($thread, $user) as $credentialable) {
            $serverNames = $serverNames->merge(
                $this->mcpChannelsFor($credentialable)
                    ->filter(fn (Channel $channel): bool => $channel->enabled && is_string($channel->server))
                    ->pluck('server')
                    ->all()
            );
        }

        return $serverNames
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): array => $this->resolve(trim($name), $thread, $user))
            ->unique('server')
            ->values()
            ->all();
    }

    public function enabled(?Thread $thread = null, ?User $user = null): bool
    {
        return $this->available($thread, $user) !== [];
    }

    protected function defaultTimeout(): int
    {
        return $this->intValue(
            config('services.mcp.default_timeout_ms', 8000),
            8000,
        );
    }

    protected function maxTimeout(): int
    {
        return $this->intValue(
            config('services.mcp.max_timeout_ms', 30000),
            30000,
        );
    }

    protected function resolveContextServer(string $server, ?Thread $thread, ?User $user): ?Channel
    {
        foreach ($this->credentialCandidates($thread, $user) as $credentialable) {
            $contextServer = $this->findContextServerFor($credentialable, $server);
            if ($contextServer) {
                return $contextServer;
            }
        }

        return null;
    }

    /**
     * @return list<Model>
     */
    protected function credentialCandidates(?Thread $thread, ?User $user): array
    {
        $candidates = [];

        if ($thread) {
            $candidates[] = $thread;

            $space = $this->threadContextResolver->resolveSpace($thread);
            if ($space) {
                $candidates[] = $space;
            }
        }

        if ($user) {
            $candidates[] = $user;
        }

        return $candidates;
    }

    protected function findContextServerFor(Model $owner, string $server): ?Channel
    {
        return $this->mcpChannelsFor($owner)
            ->filter(fn (Channel $channel): bool => $channel->server === $server && $channel->enabled)
            ->sortByDesc(fn (Channel $channel): int => (((int) $channel->priority) * 1000000) + (int) $channel->id)
            ->first();
    }

    /**
     * @return Collection<int, Channel>
     */
    protected function mcpChannelsFor(Model $owner): Collection
    {
        return $this->channelLinks->forContext($owner)
            ->filter(fn (Post $link): bool => in_array(
                $this->channelLinks->direction($link),
                [Channel::DirectionOutbound, Channel::DirectionBidirectional],
                true,
            ))
            ->map(function (Post $link) use ($owner): ?Channel {
                $channel = $this->channelLinks->channel($link);
                $linkConfig = $this->channelLinks->config($link);

                if (
                    ! $channel instanceof Channel
                    || ! $channel->enabled
                    || ! in_array($channel->status, [Channel::StatusActive, null], true)
                    || (
                        $channel->driver !== AgentChannels::Mcp
                        && data_get($channel->config, 'protocol') !== AgentChannels::Mcp
                        && ($linkConfig['protocol'] ?? null) !== AgentChannels::Mcp
                    )
                ) {
                    return null;
                }

                $channel->setRelation('channelLink', $link);
                $channel->setAttribute('link_context_source', class_basename($owner));

                return $channel;
            })
            ->filter(fn (mixed $channel): bool => $channel instanceof Channel)
            ->values();
    }

    protected function contextSource(Channel $contextServer): ?string
    {
        $source = $contextServer->getAttribute('link_context_source');

        return is_string($source) && $source !== '' ? $source : null;
    }

    /**
     * @return list<string>
     */
    protected function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $entry): bool => is_string($entry) && trim($entry) !== '')
            ->map(fn (string $entry): string => trim($entry))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeHeaders(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $headers = [];

        foreach ($value as $key => $headerValue) {
            if (! is_string($key) || trim($key) === '' || ! is_string($headerValue)) {
                continue;
            }

            $headers[trim($key)] = $headerValue;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    protected function headersFromContextServer(Channel $contextServer): array
    {
        $headers = [];
        $contextLink = $this->resolveContextLink($contextServer);
        $linkConfig = $contextLink instanceof Post ? $this->channelLinks->config($contextLink) : [];
        $credentials = is_array($contextServer->credentials) ? $contextServer->credentials : [];

        $authType = is_string($linkConfig['auth_type'] ?? null)
            ? strtolower(trim((string) $linkConfig['auth_type']))
            : (is_string($contextServer->auth_type) ? strtolower(trim($contextServer->auth_type)) : '');

        if ($authType === 'bearer') {
            $token = is_string($credentials['token'] ?? null) ? trim($credentials['token']) : '';
            if ($token !== '') {
                $headers['Authorization'] = "Bearer {$token}";
            }
        }

        if ($authType === 'basic') {
            $username = is_string($credentials['username'] ?? null) ? $credentials['username'] : '';
            $password = is_string($credentials['password'] ?? null) ? $credentials['password'] : '';

            if ($username !== '' || $password !== '') {
                $headers['Authorization'] = 'Basic '.base64_encode("{$username}:{$password}");
            }
        }

        if ($authType === 'header') {
            $headerName = is_string($credentials['header_name'] ?? null) ? trim($credentials['header_name']) : '';
            $headerValue = is_string($credentials['header_value'] ?? null) ? $credentials['header_value'] : '';

            if ($headerName !== '' && $headerValue !== '') {
                $headers[$headerName] = $headerValue;
            }
        }

        $extraHeaders = $this->normalizeHeaders($credentials['headers'] ?? []);

        return array_merge($headers, $extraHeaders);
    }

    protected function resolveContextLink(Channel $contextServer): ?Post
    {
        $link = $contextServer->getRelation('channelLink');

        return $link instanceof Post ? $link : null;
    }

    protected function intValue(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
