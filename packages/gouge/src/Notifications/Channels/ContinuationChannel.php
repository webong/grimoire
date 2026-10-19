<?php

namespace Webong\Gouge\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Webong\Gouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\Gouge\Notifications\Support\ThreadPromptTransport;

class ContinuationChannel
{
    public function __construct(
        protected DatabaseChannel $databaseChannel,
        protected ThreadPromptTransport $threadPromptTransport,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $this->databaseChannel->send($notifiable, $notification);

        $this->threadPromptTransport->send(
            notifiable: $notifiable,
            notification: $notification,
            conversationPersistenceMode: ConversationPersistenceResolver::ThreadContinuation,
        );
    }
}
