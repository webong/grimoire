<?php

namespace Webong\Gouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\Gouge\Ai\Support\Acp\AcpRegistry;
use Webong\Gouge\Ai\Tools\Diagnostics\EncodesToolResponse;

class ListAvailableAcpAgentsTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected ?Thread $thread = null,
        protected ?User $actor = null,
        protected AcpRegistry $registry = new AcpRegistry,
    ) {}

    public function description(): Stringable|string
    {
        return 'List registered ACP agents available through the current thread, space, or user channels.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        if (! $this->registry->enabled($this->thread, $this->actor)) {
            return $this->ok([
                'enabled' => false,
                'count' => 0,
                'agents' => [],
            ]);
        }

        $includeHeaders = (bool) ($request['include_headers'] ?? false);
        $agents = collect($this->registry->agents($this->thread, $this->actor))
            ->map(function (array $agent) use ($includeHeaders): array {
                $payload = [
                    'id' => $agent['id'],
                    'label' => $agent['label'] ?? null,
                    'endpoint' => $agent['endpoint'],
                    'transport' => $agent['transport'] ?? 'jsonrpc-http',
                    'auth_type' => $agent['auth_type'] ?? 'none',
                    'has_token' => is_string($agent['token'] ?? null) && trim((string) $agent['token']) !== '',
                    'allowed_methods' => $agent['allowed_methods'] ?? [],
                    'session' => [
                        'reuse' => data_get($agent, 'session.reuse'),
                        'create_method' => data_get($agent, 'session.create_method'),
                        'load_method' => data_get($agent, 'session.load_method'),
                        'prompt_method' => data_get($agent, 'session.prompt_method'),
                    ],
                ];

                if ($includeHeaders) {
                    $payload['headers'] = $agent['headers'] ?? [];
                }

                return $payload;
            })
            ->values()
            ->all();

        return $this->ok([
            'enabled' => true,
            'count' => count($agents),
            'agents' => $agents,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'include_headers' => $schema->boolean(),
        ];
    }
}
