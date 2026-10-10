<?php

namespace Webong\Gouge\Ai\Middleware\Rules;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class ValidateInputContract
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        return $next($prompt->append(implode("\n", [
            'Input contract rules:',
            '- Treat user payload shape as content.{text,attachments,actions,errors}.',
            '- Treat protocol metadata as extra.a2ui.{config,surface} when present.',
            '- Never assume legacy body/content.action/content.userAction/content.error fields.',
            '- If content.text is empty but actions/errors exist, treat it as an A2UI interaction event, not missing input.',
            '- Only ask for a follow-up input when content.text is empty and there are no attachments, actions, or errors.',
        ])));
    }
}
