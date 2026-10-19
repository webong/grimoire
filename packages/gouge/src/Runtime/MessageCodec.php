<?php

namespace Webong\Gouge\Runtime;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

final class MessageCodec
{
    public static function encode(Message $message): array
    {
        if ($message instanceof AssistantMessage) {
            return ['role' => 'assistant', 'content' => $message->content,
                'tool_calls' => $message->toolCalls->map->toArray()->all(),
                'provider_content' => $message->providerContentBlocks,
                'provider' => $message->providerContentBlocksProvider];
        }
        if ($message instanceof ToolResultMessage) {
            return ['role' => 'tool_result', 'tool_results' => $message->toolResults->map->toArray()->all()];
        }
        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            throw new InvalidArgumentException('Attachment persistence requires a custom runtime executor.');
        }
        if ($message->role->value !== 'user') {
            throw new InvalidArgumentException('Unsupported history message role.');
        }

        return ['role' => 'user', 'content' => $message->content];
    }

    public static function decode(array $message): Message
    {
        return match ($message['role']) {
            'user' => new UserMessage($message['content']),
            'assistant' => new AssistantMessage($message['content'],
                new Collection(array_map(ToolCall::fromArray(...), $message['tool_calls'] ?? [])),
                $message['provider_content'] ?? [], $message['provider'] ?? null),
            'tool_result' => new ToolResultMessage(new Collection(array_map(ToolResult::fromArray(...), $message['tool_results']))),
            default => throw new InvalidArgumentException('Unsupported history message role.'),
        };
    }
}
