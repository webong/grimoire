<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\ThreadEvent;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Sajya\Client\Client as JsonRpcClient;
use Stringable;
use Throwable;
use Webong\LaravelGouge\Ai\Support\A2a\A2aRegistry;
use Webong\LaravelGouge\Ai\Tools\Diagnostics\EncodesToolResponse;
use Webong\LaravelGouge\Support\AgentEvents;

class InvokeA2aAgentTool implements Tool
{
    use EncodesToolResponse;

    public function __construct(
        protected Thread $thread,
        protected User $actor,
        protected ?ThreadActor $threadActor = null,
        protected A2aRegistry $registry = new A2aRegistry,
    ) {}

    public function description(): Stringable|string
    {
        return 'Invoke an allowlisted outbound A2A remote agent over JSON-RPC and return the normalized response.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        if (! $this->registry->enabled($this->thread, $this->actor)) {
            return $this->error('Outbound A2A calls are disabled.');
        }

        $agentId = trim((string) ($request['agent'] ?? ''));
        $method = trim((string) ($request['method'] ?? ''));
        $params = $request['params'] ?? [];
        $rpcId = $request['rpc_id'] ?? null;
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
            return $this->error('Unknown remote A2A agent.');
        }

        $trustDecision = $this->registry->trustDecision($agent);

        if (! ($trustDecision['allowed'] ?? false)) {
            $reason = (string) ($trustDecision['reason'] ?? 'Remote A2A agent endpoint URL is not allowed by policy.');
            $this->recordEvent($agentId, $method, false, $reason, 'a2a_endpoint_denied');

            return $this->ok([
                'allowed' => false,
                'agent' => $agentId,
                'method' => $method,
                'error' => $reason,
            ]);
        }

        if (! $this->registry->isMethodAllowed($agent, $method)) {
            return $this->ok([
                'allowed' => false,
                'agent' => $agentId,
                'method' => $method,
                'error' => 'Method is not allowlisted for this agent.',
            ]);
        }

        $timeoutSeconds = max(3, min(120, $timeoutSeconds));

        try {
            $pendingRequest = Http::baseUrl((string) $agent['endpoint'])
                ->acceptJson()
                ->asJson()
                ->timeout($timeoutSeconds)
                ->connectTimeout(min(10, $timeoutSeconds))
                ->withHeaders($this->requestHeaders($agent));

            $client = new JsonRpcClient($pendingRequest);
            $response = $client->execute(
                method,
                $params,
                is_string($rpcId) && trim($rpcId) !== '' ? trim($rpcId) : null,
            );
        } catch (Throwable $exception) {
            $this->recordEvent($agentId, $method, false, $exception->getMessage(), 'a2a_outbound_exception');

            return $this->ok([
                'allowed' => true,
                'agent' => $agentId,
                'method' => $method,
                'ok' => false,
                'error_code' => 'a2a_outbound_exception',
                'error_message' => $exception->getMessage(),
                'result' => null,
            ]);
        }

        $httpResponse = $response->response();
        $httpStatus = method_exists($httpResponse, 'status') ? $httpResponse->status() : null;
        $rpcError = $response->error();
        $successful = $rpcError === null;

        $this->recordEvent(
            agentId: $agentId,
            method: $method,
            successful: $successful,
            errorMessage: is_array($rpcError) ? (string) ($rpcError['message'] ?? '') : null,
            errorCode: $successful ? null : 'a2a_remote_error',
        );

        return $this->ok([
            'allowed' => true,
            'agent' => $agentId,
            'method' => $method,
            'rpc_id' => $response->id(),
            'http_status' => $httpStatus,
            'ok' => $successful,
            'error' => $rpcError,
            'result' => $response->result(),
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

    /**
     * @param  array<string, mixed>  $agent
     * @return array<string, string>
     */
    protected function requestHeaders(array $agent): array
    {
        $headers = is_array($agent['headers'] ?? null) ? $agent['headers'] : [];
        $authType = strtolower((string) ($agent['auth_type'] ?? 'none'));
        $token = is_string($agent['token'] ?? null) ? trim((string) $agent['token']) : '';

        if ($authType === 'bearer' && $token !== '') {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }

    protected function recordEvent(
        string $agentId,
        string $method,
        bool $successful,
        ?string $errorMessage = null,
        ?string $errorCode = null,
    ): void {
        $this->thread->events()->create([
            'thread_actor_id' => $this->threadActor?->id,
            'post_id' => null,
            'event_key' => 'a2a_outbound_tool',
            'layer' => ThreadEvent::LayerExecution,
            'kind' => AgentEvents::A2a,
            'operation' => $method,
            'state' => $successful ? ThreadEvent::StateCompleted : ThreadEvent::StateFailed,
            'event_type' => $successful ? 'a2a.outbound.success' : 'a2a.outbound.failure',
            'severity' => $successful ? 'low' : 'medium',
            'payload' => [
                'agent' => $agentId,
                'method' => $method,
                'actor_id' => $this->actor->id,
                'actor_uuid' => $this->actor->uuid,
                'error_code' => $errorCode,
                'error_message' => $errorMessage !== null ? mb_substr(trim($errorMessage), 0, 500) : null,
            ],
        ]);
    }
}
