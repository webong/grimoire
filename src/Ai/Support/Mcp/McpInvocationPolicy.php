<?php

namespace Webong\LaravelGouge\Ai\Support\Mcp;

use App\Models\Server\Thread;
use App\Models\Server\User;
use App\Support\Security\UrlTrustPolicy;

class McpInvocationPolicy
{
    public function __construct(
        protected McpRegistry $serverResolver = new McpRegistry,
        protected UrlTrustPolicy $urlTrustPolicy = new UrlTrustPolicy,
    ) {}

    /**
     * @return array{allowed: bool, reason?: string, context?: array<string, mixed>}
     */
    public function authorize(string $server, string $tool, ?Thread $thread = null, ?User $user = null): array
    {
        $context = $this->serverResolver->resolve($server, $thread, $user);

        if (! ($context['enabled'] ?? false)) {
            return [
                'allowed' => false,
                'reason' => 'MCP is disabled.',
                'context' => $context,
            ];
        }

        $transport = is_string($context['transport'] ?? null)
            ? strtolower((string) $context['transport'])
            : 'http';
        $mode = is_string($context['mode'] ?? null)
            ? strtolower((string) $context['mode'])
            : 'remote';

        if ($mode === 'local' || $transport === 'local') {
            $handler = $context['handler'] ?? null;
            if (! is_string($handler) || trim($handler) === '') {
                return [
                    'allowed' => false,
                    'reason' => 'MCP handler is not configured for this local server.',
                    'context' => $context,
                ];
            }
        } else {
            $endpointUrl = $context['endpoint_url'] ?? null;
            if (! is_string($endpointUrl) || trim($endpointUrl) === '') {
                return [
                    'allowed' => false,
                    'reason' => 'MCP endpoint URL is not configured for this remote server.',
                    'context' => $context,
                ];
            }

            $trust = $this->urlTrustPolicy->authorize(
                $endpointUrl,
                is_array(config('services.mcp.trust')) ? config('services.mcp.trust') : [],
            );

            if (! ($trust['allowed'] ?? false)) {
                return [
                    'allowed' => false,
                    'reason' => (string) ($trust['reason'] ?? 'MCP endpoint URL is not allowed by policy.'),
                    'context' => $context,
                ];
            }
        }

        $allowedTools = $context['tools'] ?? [];
        if (! is_array($allowedTools) || $allowedTools === []) {
            return [
                'allowed' => false,
                'reason' => 'No MCP tools are allowlisted for this server.',
                'context' => $context,
            ];
        }

        if (! in_array('*', $allowedTools, true) && ! in_array($tool, $allowedTools, true)) {
            return [
                'allowed' => false,
                'reason' => 'MCP tool is not allowlisted for this server.',
                'context' => $context,
            ];
        }

        return [
            'allowed' => true,
            'context' => $context,
        ];
    }
}
