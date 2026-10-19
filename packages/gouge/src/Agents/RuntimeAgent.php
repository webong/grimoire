<?php

namespace Webong\Gouge\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Webong\Gouge\Runtime\MessageCodec;

final class RuntimeAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(private string $instruction, private array $history = [], private array $admittedTools = []) {}

    public function instructions(): string
    {
        return $this->instruction;
    }

    public function messages(): iterable
    {
        return array_map(MessageCodec::decode(...), $this->history);
    }

    public function tools(): iterable
    {
        return $this->admittedTools;
    }
}
