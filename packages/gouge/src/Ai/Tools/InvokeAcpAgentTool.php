<?php

namespace Webong\Gouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\Gouge\Ai\Support\Acp\AcpRegistry;
use Webong\Gouge\Ai\Support\Acp\OutboundAcpClient;
use Webong\Gouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\Gouge\Support\AgentEvents;

class InvokeAcpAgentTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
        protected ?ThreadActor $threadActor = null,
        protected AcpRegistry $registry = new AcpRegistry,
        protected OutboundAcpClient $client = new OutboundAcpClient,
    ) {}

    public function description(): Stringable|string
    {
        return 'Invoke a registered ACP agent over its configured connection and return the normalized response.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        if (! $this->registry->enabled($this->thread, $this->actor)) {
            return $this->error('No ACP channels are registered for this context.');
        }

        $agentId = trim((string) ($request['agent'] ?? ''));
        $method = trim((string) ($request['method'] ?? ''));
        $params = $request['params'] ?? [];
        $rpcId = is_string($request['rpc_id'] ?? null) ? trim((string) $request['rpc_id']) : null;
        $timeoutSeconds = isset($request['timeout_seconds'])
            ? (int) $request['timeout_seconds']
            : $this->registry->defaultTimeoutSeconds();

        if ($agentId === '' || $method === '') {
            return $this->error('Both agent and method are required.');
        }

        if (! is_array($params)) {
            return $this->error('params must be a JSON object.');
        }

        $agent = $this->registry->find($agentId, $this->thread, $this->actor);

        if (! is_array($agent)) {
            return $this->error('Unknown remote ACP agent.');
        }

        if (! $this->registry->isMethodAllowed($agent, $method)) {
            return $this->ok([
                'allowed' => false,
                'agent' => $agentId,
                'method' => $method,
                'error' => 'Method is not allowlisted for this agent.',
            ]);
        }

        $response = $this->client->execute(
            agent: $agent,
            method: $method,
            params: $params,
            rpcId: $rpcId,
            timeoutSeconds: max(3, min(180, $timeoutSeconds)),
        );

        $this->recordEvent(
            agentId: $agentId,
            method: $method,
            successful: (bool) ($response['ok'] ?? false),
            errorMessage: is_string($response['error_message'] ?? null) ? $response['error_message'] : null,
        );

        return $this->ok([
            'allowed' => true,
            'agent' => $agentId,
            'method' => $method,
            'rpc_id' => $response['rpc_id'] ?? null,
            'http_status' => $response['http_status'] ?? null,
            'ok' => (bool) ($response['ok'] ?? false),
            'error' => $response['error'] ?? null,
            'error_code' => $response['error_code'] ?? null,
            'error_message' => $response['error_message'] ?? null,
            'result' => $response['result'] ?? null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()->required(),
            'method' => $schema->string()->required(),
            'params' => $schema->object(),
            'rpc_id' => $schema->string(),
            'timeout_seconds' => $schema->integer(),
        ];
    }

    protected function recordEvent(string $agentId, string $method, bool $successful, ?string $errorMessage = null): void
    {
        $this->thread->events()->create([
            'thread_actor_id' => $this->threadActor?->id,
            'post_id' => null,
            'event_key' => 'acp_invoke_tool',
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::Acp,
            'operation' => $method,
            'state' => $successful ? ThreadEvent::StateCompleted : ThreadEvent::StateFailed,
            'event_type' => $successful ? 'acp.outbound.success' : 'acp.outbound.failure',
            'severity' => $successful ? 'low' : 'medium',
            'payload' => [
                'agent' => $agentId,
                'method' => $method,
                'actor_id' => $this->actor->id,
                'actor_uuid' => $this->actor->uuid,
                'error_message' => $errorMessage !== null ? mb_substr(trim($errorMessage), 0, 500) : null,
            ],
        ]);
    }
}
