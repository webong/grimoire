<?php

namespace Webong\Gouge\Ai\Support\Mcp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class McpRemoteEndpointClient
{
    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function callTool(
        string $endpointUrl,
        string $tool,
        array $arguments = [],
        array $headers = [],
        ?string $idempotencyKey = null,
        int $timeoutMs = 8000
    ): array {
        $payload = $this->sendJsonRpc(
            endpointUrl: $endpointUrl,
            method: 'tools/call',
            params: [
                'name' => $tool,
                'arguments' => $arguments,
            ],
            headers: $headers,
            timeoutMs: $timeoutMs,
            idempotencyKey: $idempotencyKey,
        );

        return $this->normalizeToolCallPayload($payload);
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    public function listTools(
        string $endpointUrl,
        array $headers = [],
        int $timeoutMs = 8000
    ): array {
        $payload = $this->sendJsonRpc(
            endpointUrl: $endpointUrl,
            method: 'tools/list',
            params: [],
            headers: $headers,
            timeoutMs: $timeoutMs,
            idempotencyKey: null,
        );

        if (! is_array($payload)) {
            return [];
        }

        $tools = data_get($payload, 'result.tools', []);
        if (! is_array($tools)) {
            return [];
        }

        return collect($tools)
            ->map(fn (mixed $tool): ?string => is_array($tool) && is_string($tool['name'] ?? null)
                ? trim($tool['name'])
                : null)
            ->filter(fn (?string $name): bool => is_string($name) && $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    protected function sendJsonRpc(
        string $endpointUrl,
        string $method,
        array $params,
        array $headers,
        int $timeoutMs,
        ?string $idempotencyKey
    ): ?array {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) ceil(max(250, $timeoutMs) / 1000));

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        if (is_string($idempotencyKey) && trim($idempotencyKey) !== '') {
            $request = $request->withHeader('X-Idempotency-Key', trim($idempotencyKey));
        }

        $response = $request->post($endpointUrl, [
            'jsonrpc' => '2.0',
            'id' => (string) Str::uuid(),
            'method' => $method,
            'params' => $params,
        ]);

        if (! $response->ok()) {
            return [
                'error' => [
                    'code' => $response->status(),
                    'message' => $response->body(),
                ],
            ];
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    protected function normalizeToolCallPayload(?array $payload): array
    {
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'status' => 500,
                'error_code' => 'invalid_remote_response',
                'error_message' => 'Remote MCP endpoint returned an invalid response.',
                'data' => null,
            ];
        }

        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            return [
                'ok' => false,
                'status' => 500,
                'error_code' => (string) ($error['code'] ?? 'remote_error'),
                'error_message' => (string) ($error['message'] ?? 'Remote MCP error'),
                'data' => $payload,
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'error_code' => null,
            'error_message' => null,
            'data' => $payload['result'] ?? $payload,
        ];
    }
}
