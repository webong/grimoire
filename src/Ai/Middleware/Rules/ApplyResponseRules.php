<?php

namespace Webong\Gouge\Ai\Middleware\Rules;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class ApplyResponseRules
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $rules = [
            'Response rules:',
            '- Keep responses concise and actionable.',
            '- Prefer one clear next step; at most three short bullets when listing options.',
            '- Never invent request/order IDs, statuses, or tool outcomes.',
            '- If a required detail is missing, ask one focused follow-up question.',
            '- Decide output format per turn: plain text or A2UI JSON.',
            '- Prefer A2UI when the input clearly indicates UI interaction (actions/errors/capabilities, explicit A2UI request, or interactive form request).',
            '- If plain text is sufficient, respond in plain text without JSON wrappers.',
            '- If emitting A2UI, output valid JSON only (no markdown fences) using keys: beginRendering, surfaceUpdate, dataModelUpdate, deleteSurface.',
            '- For local sub-agent work: call get_sub_agent_invocation_context first, then call invoke_sub_agent with suggested trace_id and parent_invocation_id.',
            '- Reuse the same trace_id across related sub-agent calls in the same user turn unless explicitly resetting workflow.',
        ];

        $signalRules = $this->a2uiSignalRules($prompt);

        if ($signalRules !== []) {
            $rules = [...$rules, ...$signalRules];
        }

        return $next($prompt->append(implode("\n", $rules)));
    }

    /**
     * @return array<int, string>
     */
    protected function a2uiSignalRules(AgentPrompt $prompt): array
    {
        $inboundMessage = $this->resolveLatestInboundMessage($prompt);

        if (! $inboundMessage instanceof Post) {
            return [];
        }

        $rules = [];

        if (is_array($inboundMessage->actions) && $inboundMessage->actions !== []) {
            $rules[] = '- Signal: inbound content.actions is present; treat this as an active UI interaction step.';
        }

        if (is_array($inboundMessage->errors) && $inboundMessage->errors !== []) {
            $rules[] = '- Signal: inbound content.errors is present; handle the UI error flow and recover with clear next action.';
        }

        $dataModel = $this->trimmedString(data_get($inboundMessage->meta, 'a2ui_client_data_model'));
        $capabilities = data_get($inboundMessage->meta, 'a2ui_client_capabilities');

        if ($dataModel !== null) {
            $rules[] = "- Signal: client data model is {$dataModel}.";
        }

        if (is_array($capabilities) && $capabilities !== []) {
            $rules[] = '- Signal: client A2UI capabilities were provided; keep payload compatible with those capabilities.';
        }

        $text = $this->trimmedString($inboundMessage->text);

        if ($text !== null && str_contains(strtolower($text), 'a2ui')) {
            $rules[] = '- Signal: prompt text explicitly references A2UI.';
        }

        return $rules;
    }

    protected function resolveLatestInboundMessage(AgentPrompt $prompt): ?Post
    {
        $thread = $this->resolveThread($prompt);

        if (! $thread instanceof Thread) {
            return null;
        }

        return Post::query()
            ->forThread($thread)
            ->whereIn('meta->source', ['agent_prompt', 'peer_message'])
            ->orderByDesc('id')
            ->first();
    }

    protected function resolveThread(AgentPrompt $prompt): ?Thread
    {
        $agent = $prompt->agent;

        if (! property_exists($agent, 'thread')) {
            return null;
        }

        $thread = $agent->thread;

        return $thread instanceof Thread ? $thread : null;
    }

    protected function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
