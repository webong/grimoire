<?php

namespace Webong\LaravelGouge\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Webong\LaravelGouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\LaravelGouge\Notifications\Support\ThreadPromptTransport;

class CompletionChannel
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
            conversationPersistenceMode: ConversationPersistenceResolver::ThreadCompletion,
        );
    }
}
