<?php

namespace Webong\Gouge\Runtime;

final readonly class ExecutionResult
{
    public function __construct(public string $text, public array $messages = [], public array $telemetry = []) {}
}
