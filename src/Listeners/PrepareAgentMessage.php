<?php

namespace Webong\LaravelGouge\Listeners;

use App\Events\Server\Chat\ThreadMessageDispatched;
use App\Models\Server\Thread;
use App\Models\Server\User;
use Webong\LaravelGouge\Ai\Support\Knowledge\MessageAttachmentStoreIngestor;
use Webong\LaravelGouge\Features\Actions\Chat\QueuePresenterReplies;
use Webong\LaravelGouge\Features\Actions\Chat\ResolveActiveThreadPresenters;

class PrepareAgentMessage
{
    public function __construct(
        protected ResolveActiveThreadPresenters $resolveActiveThreadPresenters,
        protected QueuePresenterReplies $queuePresenterReplies,
        protected MessageAttachmentStoreIngestor $messageAttachmentStoreIngestor,
    ) {}

    public function handle(ThreadMessageDispatched $event): void
    {
        $post = $event->post;
        $thread = $post->postable;
        $actor = $post->sender();

        if (! $thread instanceof Thread || ! $actor instanceof User) {
            return;
        }

        $presenters = $this->resolveActiveThreadPresenters->execute($thread);

        if ($post->getMedia($post::AttachmentCollection)->isNotEmpty()) {
            $this->messageAttachmentStoreIngestor->ingest($thread, $post, $actor);
        }

        if ($presenters->isNotEmpty()) {
            $this->queuePresenterReplies->execute(
                thread: $thread,
                userMessage: $post,
                actor: $actor,
                presenters: $presenters,
                broadcastSpaceId: "threads.{$thread->uuid}",
            );
        }
    }
}
