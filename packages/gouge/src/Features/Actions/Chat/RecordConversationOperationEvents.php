<?php

namespace Webong\Gouge\Features\Actions\Chat;

use App\Models\Server\Thread;
use App\Models\Server\ThreadEvent;
use Webong\Gouge\Support\AgentEvents;

class RecordConversationOperationEvents
{
    /**
     * @param  list<array<string, mixed>>  $actions
     */
    public function execute(Thread $thread, array $actions): void
    {
        foreach ($actions as $action) {
            $eventType = (string) $action['event_type'];

            $thread->events()->create([
                'thread_actor_id' => null,
                'post_id' => null,
                'event_key' => 'orchestrator',
                'layer' => ThreadEvent::LayerExecution,
                'kind' => AgentEvents::Orchestration,
                'operation' => str_replace('orchestration.', '', $eventType),
                'state' => ThreadEvent::StateCompleted,
                'event_type' => $eventType,
                'severity' => 'low',
                'payload' => $action,
            ]);
        }
    }
}
