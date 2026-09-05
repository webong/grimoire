<?php

namespace Webong\LaravelGouge\Features\Actions\Chat\Protocols;

use App\Features\Actions\Chat\Contracts\InboundMessageReceiver;
use App\Features\Actions\Chat\Contracts\OutboundMessageSender;
use App\Features\Actions\Chat\Contracts\ProtocolDriver;
use App\Features\Actions\Chat\ProtocolWebhook;
use Webong\LaravelGouge\Features\Actions\Chat\AgentPromptOutboundMessageSender;

class AgentPromptProtocol implements ProtocolDriver
{
    public const Key = 'agent_prompt';

    public function __construct(protected AgentPromptOutboundMessageSender $agentPromptOutboundMessageSender) {}

    public function key(): string
    {
        return self::Key;
    }

    /**
     * @return array<string, InboundMessageReceiver>
     */
    public function inboundReceivers(): array
    {
        return [];
    }

    public function outboundSender(): ?OutboundMessageSender
    {
        return $this->agentPromptOutboundMessageSender;
    }

    /**
     * @return list<ProtocolWebhook>
     */
    public function webhooks(): array
    {
        return [];
    }

    public function registerRoutes(): void {}
}
