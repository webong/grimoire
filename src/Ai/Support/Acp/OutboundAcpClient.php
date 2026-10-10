<?php

namespace Webong\Gouge\Ai\Support\Acp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class OutboundAcpClient
{
    /**
     * @param  array<string, mixed>  $agent
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function execute(array $agent, string $method, array $params = [], ?string $rpcId = null, int $timeoutSeconds = 20): array
    {
        $transport = strtolower((string) ($agent['transport'] ?? 'jsonrpc-http'));
        $agentId = (string) ($agent['id'] ?? 'unknown');

        if (! in_array($transport, ['jsonrpc-http', 'acp-gateway-http'], true)) {
            return [
                'ok' => false,
                'agent' => $agentId,
                'method' => $method,
                'rpc_id' => null,
                'http_status' => null,
                'result' => null,
                'error' => null,
                'error_code' => 'unsupported_transport',
                'error_message' => "Unsupported ACP transport [{$transport}].",
            ];
        }

        $resolvedRpcId = is_string($rpcId) && trim($rpcId) !== ''
            ? trim($rpcId)
            : (string) Str::ulid();

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->timeout(max(3, min(180, $timeoutSeconds)))
                ->connectTimeout(min(10, max(3, min(180, $timeoutSeconds))))
                ->withHeaders($this->requestHeaders($agent));

            $response = match ($transport) {
                'acp-gateway-http' => $request->post((string) $agent['endpoint'], [
                    'agent' => (string) ($agent['gateway_agent'] ?? $agentId),
                    'jsonrpc' => '2.0',
                    'id' => $resolvedRpcId,
                    'method' => $method,
                    'params' => $params,
                    'timeout_seconds' => max(3, min(180, $timeoutSeconds)),
                ]),
                default => $request->post((string) $agent['endpoint'], [
                    'jsonrpc' => '2.0',
                    'id' => $resolvedRpcId,
                    'method' => $method,
                    'params' => $params,
                ]),
            };
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'agent' => $agentId,
                'method' => $method,
                'rpc_id' => $resolvedRpcId,
                'http_status' => null,
                'result' => null,
                'error' => null,
                'error_code' => 'acp_outbound_exception',
                'error_message' => $exception->getMessage(),
            ];
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'agent' => $agentId,
                'method' => $method,
                'rpc_id' => $resolvedRpcId,
                'http_status' => $response->status(),
                'result' => null,
                'error' => null,
                'error_code' => 'invalid_remote_response',
                'error_message' => 'Remote ACP endpoint returned an invalid JSON-RPC payload.',
            ];
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : null;

        return [
            'ok' => $response->successful() && $error === null,
            'agent' => $agentId,
            'method' => $method,
            'rpc_id' => is_string($decoded['id'] ?? null) ? $decoded['id'] : $resolvedRpcId,
            'http_status' => $response->status(),
            'result' => $decoded['result'] ?? null,
            'error' => $error,
            'error_code' => $error !== null && array_key_exists('code', $error)
                ? (string) $error['code']
                : ($error !== null ? 'acp_remote_error' : null),
            'error_message' => is_string($error['message'] ?? null)
                ? $error['message']
                : null,
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
}
