<?php

namespace Webong\Gouge\Support;

final class AgentAbilities
{
    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return [
            'mcp:use' => 'Use MCP servers hosted by Figurate.',
            'acp:use' => 'Use the ACP transport.',
            'a2a:message.send' => 'Send A2A messages.',
            'a2a:task.read' => 'Read A2A task state.',
            'a2a:task.cancel' => 'Cancel A2A tasks.',
            'a2a:push.config.manage' => 'Manage A2A push notification configuration.',
            'invocations:read' => 'Read invocation turns.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::descriptions());
    }

    /**
     * @return list<string>
     */
    public static function defaults(): array
    {
        return array_values(array_filter(
            self::values(),
            fn (string $ability): bool => $ability !== 'invocations:read',
        ));
    }
}
