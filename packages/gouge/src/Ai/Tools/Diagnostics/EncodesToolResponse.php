<?php

namespace Webong\Gouge\Ai\Tools\Diagnostics;

trait EncodesToolResponse
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ok(array $payload = []): string
    {
        return json_encode([
            'ok' => true,
            ...$payload,
        ], JSON_UNESCAPED_SLASHES);
    }

    protected function error(string $message): string
    {
        return json_encode([
            'ok' => false,
            'error' => $message,
        ], JSON_UNESCAPED_SLASHES);
    }
}
