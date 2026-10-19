<?php

namespace Webong\Gouge\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class CountingTool implements Tool
{
    public int $calls = 0;

    public function name(): string
    {
        return 'lookup';
    }

    public function description(): string
    {
        return 'Return a deterministic value.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $this->calls++;

        return '42';
    }
}
