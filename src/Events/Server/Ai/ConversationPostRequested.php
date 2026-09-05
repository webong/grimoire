<?php

namespace Webong\LaravelGouge\Events\Server\Ai;

use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationPostRequested
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array{title?: string, description?: string}|null  $subject
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public Thread $thread,
        public Space $space,
        public User $actor,
        public string $intent,
        public ?string $title = null,
        public ?string $description = null,
        public ?array $subject = null,
        public ?string $status = null,
        public ?array $response = null,
    ) {}

    public function isSubjectIntent(): bool
    {
        return $this->intent === 'subject';
    }

    public function isExecutionIntent(): bool
    {
        return $this->intent === 'execution';
    }

    public function handled(): bool
    {
        return is_array($this->response);
    }

    public function subjectTitle(): ?string
    {
        return is_string($this->subject['title'] ?? null) ? $this->subject['title'] : null;
    }

    public function subjectDescription(): ?string
    {
        return is_string($this->subject['description'] ?? null) ? $this->subject['description'] : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function respond(array $response): void
    {
        $this->response = $response;
    }

    public function fail(string $message): void
    {
        $this->respond([
            'ok' => false,
            'error' => $message,
        ]);
    }
}
