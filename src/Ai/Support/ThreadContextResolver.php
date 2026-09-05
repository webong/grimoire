<?php

namespace Webong\LaravelGouge\Ai\Support;

use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;

class ThreadContextResolver
{
    public function resolveSpace(Thread $thread): ?Space
    {
        $threadable = $thread->threadable;

        if ($threadable instanceof Space) {
            return $threadable;
        }

        $relatedSpace = $thread->relatedOne(Space::class);

        if ($relatedSpace instanceof Space) {
            return $relatedSpace;
        }

        if ($threadable instanceof Post) {
            $postable = $threadable->postable;

            if ($postable instanceof Space) {
                return $postable;
            }

            $relatedSpace = $threadable->relatedOne(Space::class);

            if ($relatedSpace instanceof Space) {
                return $relatedSpace;
            }
        }

        return null;
    }
}
