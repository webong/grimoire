<?php

namespace Webong\LaravelGouge\Tests\Feature;

use App\Features\Actions\Chat\DispatchThreadMessage;
use App\Features\Actions\Chat\ThreadMessageEntry;
use App\Models\Server\Space;
use App\Models\Server\StoreDocument;
use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispatchThreadMessageAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_thread_message_attachments_as_post_media(): void
    {
        $actor = User::factory()->create();
        $space = Space::factory()->create();
        $thread = $space->threads()->create([
            'purpose' => Thread::PurposeMain,
            'title' => 'Attachment Thread',
            'phase' => 'execution',
            'status' => 'open',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'thread-attachment-');
        $this->assertIsString($path);
        file_put_contents($path, 'Attached content for indexing.');

        $post = app(DispatchThreadMessage::class)->execute(new ThreadMessageEntry(
            thread: $thread,
            space: null,
            actor: $actor,
            text: 'Attached file',
            attachments: collect([[
                'path' => $path,
                'original_name' => 'brief.txt',
            ]]),
            source: 'peer_message',
            authorizeActor: false,
        ));

        $post->load('media');

        $this->assertCount(1, $post->getMedia('attachments'));
        $this->assertCount(1, $post->attachments);
        $this->assertSame('brief.txt', $post->attachments[0]['file_name'] ?? null);
        $this->assertTrue(
            StoreDocument::query()
                ->where('post_id', $post->id)
                ->whereNotNull('media_id')
                ->exists()
        );

        @unlink($path);
    }
}
