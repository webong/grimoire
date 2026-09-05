<?php

namespace Webong\LaravelGouge\Listeners;

use App\Events\Server\Chat\ConversationThreadCreated;
use Webong\LaravelGouge\Features\Actions\Chat\EnsureThreadPresenter;

class ConfigureAgentConversationThread
{
    public function __construct(protected EnsureThreadPresenter $ensureThreadPresenter) {}

    public function handle(ConversationThreadCreated $event): void
    {
        $presenter = config('laravel-gouge.presenter');

        if (! is_string($presenter) || trim($presenter) === '') {
            return;
        }

        $this->ensureThreadPresenter->execute($event->thread, trim($presenter));
    }
}
