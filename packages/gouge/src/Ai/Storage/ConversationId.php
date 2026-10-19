<?php

namespace Webong\Gouge\Ai\Storage;

use Illuminate\Support\Str;

class ConversationId
{
    public static function toStorageId(string $conversationId): string
    {
        $normalizedConversationId = trim($conversationId);

        if ($normalizedConversationId === '') {
            return (string) Str::uuid7();
        }

        if (strlen($normalizedConversationId) <= 36 && preg_match('/^[A-Za-z0-9\-]+$/', $normalizedConversationId)) {
            return $normalizedConversationId;
        }

        $hash = md5($normalizedConversationId);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }
}
