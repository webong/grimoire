<?php

namespace Webong\Gouge\Support\Orchestrate;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadEvent;
use Webong\Gouge\Support\AgentEvents;

class TaskRecord
{
    /**
     * @param  array<string, mixed>|null  $owner
     * @param  array<string, mixed>|null  $remote
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $lastPayload
     */
    public function __construct(
        public ThreadEvent $event,
        public string $uuid,
        public string $publicId,
        public string $status,
        public ?Thread $thread,
        public ?Post $message,
        public ?string $protocol,
        public ?array $owner,
        public ?array $remote,
        public array $snapshot,
        public array $lastPayload,
        public ?string $spaceId = null,
        public ?int $userId = null,
        public ?string $userUuid = null,
        public ?string $completedAt = null,
        public ?string $failedAt = null,
        public ?string $canceledAt = null,
    ) {}

    public static function fromEvent(ThreadEvent $event): ?self
    {
        $task = data_get($event->payload, 'task');

        if (! is_array($task)) {
            return null;
        }

        $uuid = self::trimmedString($task['uuid'] ?? null);

        if ($uuid === null) {
            return null;
        }

        $publicId = self::trimmedString($task['public_id'] ?? null) ?? $uuid;
        $status = self::trimmedString($task['status'] ?? null)
            ?? self::trimmedString($event->state)
            ?? 'submitted';
        $protocol = self::trimmedString($task['protocol'] ?? null) ?? self::protocolFromKind($event->kind);
        $owner = is_array($task['owner'] ?? null) ? $task['owner'] : null;
        $remote = is_array($task['remote'] ?? null) ? $task['remote'] : null;
        $snapshot = is_array($task['snapshot'] ?? null) ? $task['snapshot'] : [];
        $lastPayload = is_array($task['last_payload'] ?? null) ? $task['last_payload'] : [];
        $userId = self::intOrNull($task['user_id'] ?? null);
        $userUuid = self::trimmedString($task['user_uuid'] ?? null);
        $timestamps = is_array($task['timestamps'] ?? null) ? $task['timestamps'] : [];

        return new self(
            event: $event,
            uuid: $uuid,
            publicId: $publicId,
            status: $status,
            thread: $event->thread,
            message: $event->post,
            protocol: $protocol,
            owner: $owner,
            remote: $remote,
            snapshot: $snapshot,
            lastPayload: $lastPayload,
            spaceId: self::trimmedString($task['space_id'] ?? null),
            userId: $userId,
            userUuid: $userUuid,
            completedAt: self::trimmedString($timestamps['completed_at'] ?? null),
            failedAt: self::trimmedString($timestamps['failed_at'] ?? null),
            canceledAt: self::trimmedString($timestamps['canceled_at'] ?? null),
        );
    }

    public function isRemote(): bool
    {
        return is_array($this->remote);
    }

    public function isLocal(): bool
    {
        return ! $this->isRemote();
    }

    protected static function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    protected static function protocolFromKind(?string $kind): ?string
    {
        return match ($kind) {
            AgentEvents::Acp => 'acp',
            AgentEvents::A2a => 'a2a',
            default => null,
        };
    }
}
