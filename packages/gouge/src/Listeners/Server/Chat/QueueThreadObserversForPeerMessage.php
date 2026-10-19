<?php

namespace Webong\Gouge\Listeners\Server\Chat;

use App\Events\Server\Chat\ThreadMessageDispatched;
use App\Models\Server\Thread;
use Webong\Gouge\Jobs\ProcessThreadObservers;

class QueueThreadObserversForPeerMessage
{
    public function handle(ThreadMessageDispatched $event): void
    {
        $post = $event->post;
        $postableType = is_string($post->postable_type) ? trim($post->postable_type) : '';
        $threadMorphClass = (new Thread)->getMorphClass();

        if (! in_array($postableType, [$threadMorphClass, Thread::class], true)) {
            return;
        }

        $meta = is_array($post->meta) ? $post->meta : [];
        if (! is_string($post->senderable_type) || $post->senderable_type === '') {
            return;
        }

        if (($meta['observer_dispatch'] ?? true) === false) {
            return;
        }

        ProcessThreadObservers::dispatch((int) $post->postable_id, (int) $post->id);
    }
}
