<?php

namespace Webong\Gouge\Features\Actions\Chat;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Webong\Gouge\Models\Server\AgentConversationMessage;

class ProjectInvocationTurns
{
    /**
     * @return array<string, mixed>
     */
    public function execute(User $actor, string $invocationId): array
    {
        $invocation = $this->invocationQuery($actor)
            ->where('invocation_id', $invocationId)
            ->firstOrFail();
        $traceId = $this->trimmedString($invocation->trace_id)
            ?? $this->trimmedString($invocation->invocation_id);

        $messages = $this->invocationQuery($actor)
            ->with('invocable')
            ->where(function ($query) use ($invocationId, $traceId): void {
                $query->where('invocation_id', $invocationId);

                if ($traceId !== null) {
                    $query->orWhere('trace_id', $traceId);
                }
            })
            ->oldest('created_at')
            ->get()
            ->map(fn (AgentConversationMessage $message): array => $this->mapMessage($message))
            ->keyBy(fn (array $message): string => (string) $message['invocation_id']);

        $requested = $messages->get($invocationId);
        abort_unless(is_array($requested), 404);

        $childrenByParent = $messages
            ->filter(fn (array $message): bool => is_string($message['parent_invocation_id']))
            ->groupBy('parent_invocation_id');

        $attachChildren = function (array $message, array $ancestors = []) use (&$attachChildren, $childrenByParent): array {
            $currentInvocationId = (string) $message['invocation_id'];

            if (in_array($currentInvocationId, $ancestors, true)) {
                return [...$message, 'children' => []];
            }

            $children = $childrenByParent
                ->get($currentInvocationId, collect())
                ->map(fn (array $child): array => $attachChildren($child, [...$ancestors, $currentInvocationId]))
                ->values()
                ->all();

            return [...$message, 'children' => $children];
        };

        return $attachChildren($requested);
    }

    protected function invocationQuery(User $actor): Builder
    {
        return AgentConversationMessage::query()
            ->where('participant_type', $actor->getMorphClass())
            ->where('participant_id', $actor->getKey())
            ->whereNotNull('invocation_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapMessage(AgentConversationMessage $message): array
    {
        $meta = $this->decodeJsonArray($message->meta);
        $status = $this->trimmedString($meta['status'] ?? null);

        if (! in_array($status, ['pending', 'completed', 'failed'], true)) {
            $status = trim((string) $message->content) !== ''
                ? 'completed'
                : 'pending';
        }

        return [
            'id' => $message->invocation_id,
            'status' => $status,
            'agent_message_id' => $message->id,
            'invocation_id' => $message->invocation_id,
            'trace_id' => $message->trace_id,
            'parent_invocation_id' => $message->parent_invocation_id,
            'conversation_id' => $message->conversation_id,
            'invocable' => $this->nodeReference($message->invocable),
            'agent' => $message->agent,
            'role' => $message->role,
            'content' => $message->content,
            'tool_calls' => $this->decodeJsonArray($message->tool_calls),
            'tool_results' => $this->decodeJsonArray($message->tool_results),
            'usage' => $this->decodeJsonArray($message->usage),
            'meta' => $meta,
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'children' => [],
        ];
    }

    /**
     * @return array{type: string, id: string}|null
     */
    protected function nodeReference(mixed $node): ?array
    {
        if (! $node instanceof Model) {
            return null;
        }

        return match (true) {
            $node instanceof Space => ['type' => 'space', 'id' => $node->uuid],
            $node instanceof Thread => ['type' => 'thread', 'id' => $node->uuid],
            $node instanceof Post => ['type' => 'post', 'id' => $node->ulid],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
