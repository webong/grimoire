<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Tools;

use Grimlock\LaravelAi\Contracts\ConnectorInvocationPolicy;
use Grimlock\LaravelAi\Contracts\ConnectorInvoker;
use Grimlock\LaravelAi\Contracts\ConnectorRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Throwable;

// Invokes a host-supplied connector executor, without implementing its transport. Policy is
// checked before every dispatch; the invoker owns protocol-specific I/O.
final class InvokeManagedConnectorTool implements Tool
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly ConnectorInvocationPolicy $policy,
        private readonly ConnectorInvoker $invoker,
    ) {}

    public function description(): Stringable|string
    {
        return 'Invoke an allowlisted operation through a Grimlock-managed connector.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $connectorId = trim((string) ($request['connector'] ?? ''));
        $operation = trim((string) ($request['operation'] ?? ''));
        $arguments = $request['arguments'] ?? [];

        if ($connectorId === '' || $operation === '' || ! is_array($arguments)) {
            return $this->encode(['ok' => false, 'error_code' => 'invalid_request', 'error_message' => 'connector, operation, and object arguments are required.']);
        }

        $connector = $this->connectors->connector($connectorId);
        if ($connector === null) {
            return $this->encode(['ok' => false, 'error_code' => 'connector_not_found', 'error_message' => 'Connector is unavailable.']);
        }

        $decision = $this->policy->authorize($connector, $operation);
        if (! $decision->allowed) {
            return $this->encode(['ok' => false, 'error_code' => 'policy_denied', 'error_message' => $decision->reason, 'connector' => $connector->toArray()]);
        }

        try {
            $result = $this->invoker->invoke(
                $connector,
                $operation,
                $arguments,
                is_string($request['idempotency_key'] ?? null) ? $request['idempotency_key'] : null,
                isset($request['timeout_ms']) ? (int) $request['timeout_ms'] : null,
            );
        } catch (Throwable $exception) {
            return $this->encode(['ok' => false, 'error_code' => 'connector_exception', 'error_message' => $exception->getMessage()]);
        }

        return $this->encode(['connector' => $connector->toArray(), 'operation' => $operation, ...$result->toArray()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connector' => $schema->string()->required(),
            'operation' => $schema->string()->required(),
            'arguments' => $schema->object(),
            'idempotency_key' => $schema->string(),
            'timeout_ms' => $schema->integer(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
