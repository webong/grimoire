<?php

namespace Webong\Gouge\Runtime;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Messages\AssistantMessage;
use RuntimeException;
use Webong\Gouge\Contracts\AgentExecutor;

final class NativeAgentExecutor implements AgentExecutor
{
    public function __construct(private ?string $provider = null, private ?string $model = null, private ?int $timeout = null) {}

    public function execute(Agent $agent, AgentTurn $turn): ExecutionResult
    {
        $response = $agent->prompt($turn->prompt, provider: $this->provider, model: $this->model, timeout: $this->timeout);
        if ($response->hasPendingApprovals()) {
            throw new RuntimeException('Approval continuation is not supported by this executor; the run cannot complete.');
        }
        $messages = $response->messages->isEmpty()
            ? [MessageCodec::encode(new AssistantMessage($response->text))]
            : $response->messages->map(MessageCodec::encode(...))->all();

        return new ExecutionResult($response->text, $messages, [
            'invocation_id' => $response->invocationId,
            'usage' => $response->usage->toArray(),
            'tool_calls' => $response->toolCalls->map->toArray()->all(),
            'tool_results' => $response->toolResults->map->toArray()->all(),
        ]);
    }
}
