<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\LaravelGouge\Ai\Support\A2a\A2aRegistry;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;

class ListAvailableA2aAgentsTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
        protected A2aRegistry $registry = new A2aRegistry,
    ) {}

    public function description(): Stringable|string
    {
        return 'List allowlisted outbound A2A remote agents that can be invoked by this assistant.';
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
        $allAgents = $this->registry->agents($this->thread, $this->actor);
        $agents = collect($this->registry->trustedAgents($this->thread, $this->actor))
            ->map(function (array $agent) use ($includeHeaders): array {
                $payload = [
                    'id' => $agent['id'],
                    'label' => $agent['label'] ?? null,
                    'endpoint' => $agent['endpoint'],
                    'auth_type' => $agent['auth_type'] ?? 'none',
                    'has_token' => is_string($agent['token'] ?? null) && trim((string) $agent['token']) !== '',
                    'allowed_methods' => $agent['allowed_methods'] ?? [],
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
            'filtered_out_count' => max(count($allAgents) - count($agents), 0),
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
