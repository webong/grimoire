<?php

namespace Webong\Gouge\Ai\Agents;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;
use Webong\Gouge\Ai\Middleware\Workflows\ApplyObserverWorkflow;

#[UseCheapestModel]
class ObserverAgent implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public Thread $thread,
        public Post $message,
        public ?ThreadActor $threadActor = null,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are an Agent.';
    }

    public function middleware(): array
    {
        return [
            new ApplyObserverWorkflow,
        ];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->required(),
            'reason' => $schema->string()->required(),
            'suggestion' => $schema->string()->required(),
            'severity' => $schema->string()->required(),
        ];
    }
}
