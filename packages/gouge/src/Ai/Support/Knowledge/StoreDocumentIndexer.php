<?php

namespace Webong\Gouge\Ai\Support\Knowledge;

use App\Models\Server\Post;
use App\Models\Server\Store;
use App\Models\Server\StoreDocument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Stores as AiStores;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class StoreDocumentIndexer
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function indexMedia(
        Store $store,
        Media $media,
        ?Post $post = null,
        string $origin = 'unknown',
        array $metadata = [],
    ): StoreDocument {
        $existing = StoreDocument::query()
            ->where('store_id', $store->id)
            ->where('media_id', $media->id)
            ->first();

        if ($existing instanceof StoreDocument) {
            return $existing;
        }

        $document = StoreDocument::query()->create([
            'store_id' => $store->id,
            'media_id' => $media->id,
            'post_id' => $post?->id,
            'origin' => $origin,
            'status' => 'pending',
            'meta' => [
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                ...$metadata,
            ],
        ]);

        if (! $store->external_store_id) {
            $document->forceFill(['status' => 'local_only'])->save();

            return $document;
        }

        try {
            $providerStore = AiStores::get($store->external_store_id);

            $providerDocument = $providerStore->add(
                Document::fromPath($media->getPath()),
                metadata: [
                    'store_id' => $store->id,
                    'media_id' => $media->id,
                    'origin' => $origin,
                    ...$metadata,
                ],
            );

            $document->forceFill([
                'provider_file_id' => $providerDocument->fileId,
                'provider_document_id' => $providerDocument->id,
                'status' => 'indexed',
                'meta' => Arr::except((array) $document->meta, ['index_error']),
            ])->save();
        } catch (Throwable $exception) {
            $meta = (array) $document->meta;
            $meta['index_error'] = $exception->getMessage();

            $document->forceFill([
                'status' => 'index_failed',
                'meta' => $meta,
            ])->save();
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function indexPostAttachment(
        Store $store,
        Post $post,
        array $attachment,
        string $origin = 'unknown',
        array $metadata = [],
    ): StoreDocument {
        $attachmentPath = $attachment['path'] ?? null;

        if (! $attachmentPath) {
            throw new \InvalidArgumentException('Attachment path is required.');
        }

        $existing = StoreDocument::query()
            ->where('store_id', $store->id)
            ->where('post_id', $post->id)
            ->where('attachment_path', $attachmentPath)
            ->first();

        if ($existing instanceof StoreDocument) {
            return $existing;
        }

        $fileName = $attachment['file_name'] ?? $attachment['name'] ?? 'unknown';

        $document = StoreDocument::query()->create([
            'store_id' => $store->id,
            'media_id' => null,
            'post_id' => $post->id,
            'attachment_path' => $attachmentPath,
            'origin' => $origin,
            'status' => 'pending',
            'meta' => [
                'file_name' => $fileName,
                ...$metadata,
            ],
        ]);

        if (! $store->external_store_id) {
            $document->forceFill(['status' => 'local_only'])->save();

            return $document;
        }

        try {
            $providerStore = AiStores::get($store->external_store_id);

            $providerDocument = $providerStore->add(
                Document::fromPath(Storage::path($attachmentPath)),
                metadata: [
                    'store_id' => $store->id,
                    'post_id' => $post->id,
                    'origin' => $origin,
                    ...$metadata,
                ],
            );

            $document->forceFill([
                'provider_file_id' => $providerDocument->fileId,
                'provider_document_id' => $providerDocument->id,
                'status' => 'indexed',
                'meta' => Arr::except((array) $document->meta, ['index_error']),
            ])->save();
        } catch (Throwable $exception) {
            $meta = (array) $document->meta;
            $meta['index_error'] = $exception->getMessage();

            $document->forceFill([
                'status' => 'index_failed',
                'meta' => $meta,
            ])->save();
        }

        return $document;
    }
}
