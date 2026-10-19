<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Tools;

use Grimlock\LaravelAi\Contracts\ControlPlaneClient;
use Grimlock\LaravelAi\ValueObjects\AdmittedToolCall;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Throwable;

// This native Laravel AI tool delegates authority to Grimlock. The control
// plane still verifies admission, policy, approval, and idempotency at call
// time; the Laravel runtime merely supplies its stable provider call ID.
final class InvokeAdmittedTool implements Tool
{
    public function __construct(
        private readonly ControlPlaneClient $controlPlane,
        private readonly string $sessionId,
        private readonly string $runId,
    ) {}

    public function description(): Stringable|string
    {
        return 'Invoke a Grimlock-admitted tool for the current run.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $toolName = trim((string) ($request['tool_name'] ?? ''));
        $toolVersion = trim((string) ($request['tool_version'] ?? ''));
        $arguments = $request['arguments'] ?? [];
        $idempotencyKey = trim((string) ($request['idempotency_key'] ?? $request->toolCallId() ?? ''));

        if ($toolName === '' || $toolVersion === '' || ! is_array($arguments) || $idempotencyKey === '') {
            return $this->encode([
                'ok' => false,
                'status' => 'failed',
                'error_code' => 'invalid_request',
                'error_message' => 'tool_name, tool_version, object arguments, and an idempotency key are required.',
            ]);
        }

        try {
            $result = $this->controlPlane->call(new AdmittedToolCall(
                $this->sessionId,
                $this->runId,
                $toolName,
                $toolVersion,
                $arguments,
                $idempotencyKey,
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->encode([
                'ok' => false,
                'status' => 'failed',
                'error_code' => 'invalid_request',
                'error_message' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            return $this->encode([
                'ok' => false,
                'status' => 'failed',
                'error_code' => 'control_plane_exception',
                'error_message' => $exception->getMessage(),
            ]);
        }

        return $this->encode($result->toArray());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_name' => $schema->string()->required(),
            'tool_version' => $schema->string()->required(),
            'arguments' => $schema->object(),
            'idempotency_key' => $schema->string(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
