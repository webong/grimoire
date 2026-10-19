<?php

namespace Webong\Gouge\Ai\Support\Knowledge;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\User;

class MessageAttachmentStoreIngestor
{
    public function __construct(
        protected KnowledgeStoreManager $storeManager,
        protected StoreDocumentIndexer $documentIndexer,
    ) {}

    /**
     * @return array{store_id: int, processed: int, indexed: int, failed: int, local_only: int}
     */
    public function ingest(Thread $thread, Post $post, User $actor): array
    {
        $store = $this->storeManager->forThread($thread, $actor);

        $result = [
            'store_id' => $store->id,
            'processed' => 0,
            'indexed' => 0,
            'failed' => 0,
            'local_only' => 0,
        ];

        $mediaItems = $post->getMedia(Post::AttachmentCollection);

        foreach ($mediaItems as $media) {
            try {
                $document = $this->documentIndexer->indexMedia(
                    store: $store,
                    post: $post,
                    media: $media,
                    origin: 'thread_message',
                );

                if ($document->status === 'indexed') {
                    $result['indexed']++;
                } elseif ($document->status === 'local_only') {
                    $result['local_only']++;
                }
            } catch (\Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
            $result['processed']++;
        }

        return $result;
    }
}
