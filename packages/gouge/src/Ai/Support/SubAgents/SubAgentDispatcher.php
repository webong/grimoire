<?php

namespace Webong\Gouge\Ai\Support\SubAgents;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Stringable;
use Throwable;
use Webong\Gouge\Ai\Agents\Subs\Browser;
use Webong\Gouge\Ai\Agents\Subs\Developer;
use Webong\Gouge\Ai\Agents\Subs\Explorer;
use Webong\Gouge\Ai\Agents\Subs\Manager;
use Webong\Gouge\Ai\Agents\Subs\Planner;
use Webong\Gouge\Ai\Agents\Subs\Researcher;

class SubAgentDispatcher
{
    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return collect($this->registry())
            ->map(function (string $agentClass): array {
                /** @var Agent&object $agent */
                $agent = new $agentClass;

                return [
                    'key' => method_exists($agent, 'key') ? (string) $agent->key() : class_basename($agentClass),
                    'role' => method_exists($agent, 'role') ? (string) $agent->role() : null,
                    'goal' => method_exists($agent, 'goal') ? (string) $agent->goal() : null,
                    'constraints' => method_exists($agent, 'constraints') ? (array) $agent->constraints() : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function dispatch(
        string $subAgentKey,
        string $prompt,
        array $context = [],
        ?string $traceId = null,
        ?string $parentInvocationId = null,
    ): array {
        $subAgentKey = trim($subAgentKey);
        $prompt = trim($prompt);

        if ($subAgentKey === '' || $prompt === '') {
            return [
                'ok' => false,
                'error_code' => 'invalid_sub_agent_request',
                'error' => 'Both sub_agent and prompt are required.',
            ];
        }

        $agent = $this->resolve($subAgentKey);

        if (! $agent instanceof Agent) {
            return [
                'ok' => false,
                'error_code' => 'unknown_sub_agent',
                'error' => 'Unknown sub-agent.',
            ];
        }

        $resolvedTraceId = $this->normalizedId($traceId) ?? (string) str()->ulid();
        $resolvedParentInvocationId = $this->normalizedId($parentInvocationId);
        $invocationId = (string) str()->ulid();
        $compiledPrompt = $this->buildPrompt(
            prompt: $prompt,
            context: $context,
            traceId: $resolvedTraceId,
            invocationId: $invocationId,
            parentInvocationId: $resolvedParentInvocationId,
        );

        try {
            $response = $this->invokeAgent($agent, $compiledPrompt);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error_code' => 'sub_agent_invocation_exception',
                'error' => $exception->getMessage(),
                'sub_agent' => $subAgentKey,
                'trace_id' => $resolvedTraceId,
                'invocation_id' => $invocationId,
                'parent_invocation_id' => $resolvedParentInvocationId,
                'response' => [
                    'text' => null,
                    'conversation_id' => null,
                    'provider_invocation_id' => null,
                    'fallback_text' => 'Sub-agent execution failed.',
                ],
            ];
        }

        $responseText = $this->responseText($response);

        if ($responseText === null) {
            return [
                'ok' => false,
                'error_code' => 'sub_agent_empty_response',
                'error' => 'Sub-agent returned an empty response.',
                'sub_agent' => $subAgentKey,
                'trace_id' => $resolvedTraceId,
                'invocation_id' => $invocationId,
                'parent_invocation_id' => $resolvedParentInvocationId,
                'response' => [
                    'text' => null,
                    'conversation_id' => $this->normalizedId(data_get($response, 'conversationId')),
                    'provider_invocation_id' => $this->normalizedId(data_get($response, 'invocationId')),
                    'fallback_text' => 'Sub-agent returned no actionable output.',
                ],
            ];
        }

        return [
            'ok' => true,
            'sub_agent' => $subAgentKey,
            'trace_id' => $resolvedTraceId,
            'invocation_id' => $invocationId,
            'parent_invocation_id' => $resolvedParentInvocationId,
            'response' => [
                'text' => $responseText,
                'conversation_id' => $this->normalizedId(data_get($response, 'conversationId')),
                'provider_invocation_id' => $this->normalizedId(data_get($response, 'invocationId')),
                'fallback_text' => null,
            ],
            'telemetry' => [
                'agent' => $agent::class,
                'tool_calls' => $this->toArrayValue(data_get($response, 'toolCalls')),
                'tool_results' => $this->toArrayValue(data_get($response, 'toolResults')),
                'usage' => $this->toArrayValue(data_get($response, 'usage')),
                'meta' => $this->toArrayValue(data_get($response, 'meta')),
            ],
        ];
    }

    protected function resolve(string $subAgentKey): ?Agent
    {
        $subAgentKey = trim($subAgentKey);
        $agentClass = $this->registry()[$subAgentKey] ?? null;

        if (! is_string($agentClass) || ! class_exists($agentClass)) {
            return null;
        }

        $agent = new $agentClass;

        return $agent instanceof Agent ? $agent : null;
    }

    protected function invokeAgent(Agent $agent, string $prompt): mixed
    {
        return $agent::make()->prompt($prompt);
    }

    /**
     * @return array<string, class-string<Agent>>
     */
    protected function registry(): array
    {
        return [
            'manager' => Manager::class,
            'planner' => Planner::class,
            'developer' => Developer::class,
            'explorer' => Explorer::class,
            'researcher' => Researcher::class,
            'browser' => Browser::class,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function buildPrompt(
        string $prompt,
        array $context,
        string $traceId,
        string $invocationId,
        ?string $parentInvocationId,
    ): string {
        $payload = [
            'meta' => [
                'trace_id' => $traceId,
                'invocation_id' => $invocationId,
                'parent_invocation_id' => $parentInvocationId,
            ],
            'task' => [
                'prompt' => $prompt,
                'context' => $context,
            ],
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ?: $prompt;
    }

    protected function responseText(mixed $response): ?string
    {
        if (is_string($response)) {
            $text = trim($response);

            return $text === '' ? null : $text;
        }

        if ($response instanceof Stringable) {
            $text = trim((string) $response);

            return $text === '' ? null : $text;
        }

        $responseText = data_get($response, 'text');

        if (is_string($responseText)) {
            $trimmed = trim($responseText);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_array($response)) {
            $encoded = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : null;
        }

        return null;
    }

    protected function normalizedId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function toArrayValue(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->values()->all();
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        return is_array($value) ? $value : [];
    }
}
