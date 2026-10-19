<?php

namespace Webong\Gouge\Runtime;

use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use RuntimeException;
use Webong\Gouge\Agents\RuntimeAgent;
use Webong\Gouge\Contracts\AgentFactory;

/** Trusted, deployment-supplied snapshots; network registry resolution can replace this binding. */
final class ConfiguredAgentFactory implements AgentFactory
{
    public function __construct(private Container $container, private array $definitions) {}

    public function make(AgentTurn $turn, array $history): Agent
    {
        $definition = $this->definitions[$turn->agentName.'@'.$turn->agentVersion] ?? null;
        if (! is_array($definition) || ! is_string($definition['digest'] ?? null)
            || ! hash_equals($turn->definitionDigest, $definition['digest'])
            || ! is_string($definition['instructions'] ?? null)) {
            throw new RuntimeException('The exact agent definition and digest are not registered.');
        }
        $tools = [];
        foreach ($definition['tools'] ?? [] as $class) {
            $tool = $this->container->make($class);
            if (! $tool instanceof Tool) {
                throw new RuntimeException('Registered tools must implement the Laravel AI Tool contract.');
            }
            $tools[] = $tool;
        }

        return new RuntimeAgent($definition['instructions'], $history, $tools);
    }
}
