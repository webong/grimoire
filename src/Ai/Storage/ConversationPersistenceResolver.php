<?php

namespace Webong\LaravelGouge\Ai\Storage;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request as HttpRequest;
use Webong\LaravelGouge\Ai\Storage\Contracts\ThreadConversationPersistence;
use Webong\LaravelGouge\Ai\Storage\Strategies\ThreadCompletionPersistence;
use Webong\LaravelGouge\Ai\Storage\Strategies\ThreadContinuationPersistence;

class ConversationPersistenceResolver
{
    public const ThreadContinuation = 'thread_continuation';

    public const ThreadCompletion = 'thread_completion';

    public const DualWriteThreadContinuationRead = 'dual_write_thread_continuation_read';

    public const DualWriteThreadCompletionRead = 'dual_write_thread_completion_read';

    public function __construct(
        protected ?Container $container = null,
        protected ?HttpRequest $request = null
    ) {
        $this->container ??= app();
        $this->request ??= $this->container->bound('request')
            ? $this->container->make('request')
            : null;
    }

    public function mode(?string $requestedMode = null): string
    {
        $explicitMode = self::normalizeMode($requestedMode);

        if ($explicitMode !== null) {
            return $explicitMode;
        }

        $requestInputMode = $this->request instanceof HttpRequest
            ? self::normalizeMode(
                $this->request->input('conversation_persistence')
                ?? $this->request->header('X-Conversation-Persistence')
            )
            : null;

        if ($requestInputMode !== null) {
            return $requestInputMode;
        }

        return self::ThreadContinuation;
    }

    public static function normalizeMode(mixed $mode): ?string
    {
        if (! is_string($mode) || trim($mode) === '') {
            return null;
        }

        $normalized = trim($mode);

        return match ($normalized) {
            self::ThreadContinuation,
            self::ThreadCompletion,
            self::DualWriteThreadContinuationRead,
            self::DualWriteThreadCompletionRead => $normalized,
            'legacy' => self::ThreadContinuation,
            'ai_conversations' => self::ThreadCompletion,
            'dual_write_legacy_read' => self::DualWriteThreadContinuationRead,
            'dual_write_ai_read' => self::DualWriteThreadCompletionRead,
            default => null,
        };
    }

    public function shouldUseThreadConversationIds(?string $requestedMode = null): bool
    {
        return in_array($this->mode($requestedMode), [
            self::ThreadContinuation,
            self::DualWriteThreadContinuationRead,
        ], true);
    }

    public function primary(?string $requestedMode = null): ThreadConversationPersistence
    {
        return match ($this->mode($requestedMode)) {
            self::ThreadCompletion,
            self::DualWriteThreadCompletionRead => $this->container->make(ThreadCompletionPersistence::class),
            default => $this->container->make(ThreadContinuationPersistence::class),
        };
    }

    public function secondary(?string $requestedMode = null): ?ThreadConversationPersistence
    {
        return match ($this->mode($requestedMode)) {
            self::DualWriteThreadCompletionRead => $this->container->make(ThreadContinuationPersistence::class),
            self::DualWriteThreadContinuationRead => $this->container->make(ThreadCompletionPersistence::class),
            default => null,
        };
    }
}
