<?php

namespace Webong\Gouge\Notifications\Support;

use App\Notifications\Channels\CoordinationChannel;
use Illuminate\Notifications\Notification;
use Webong\Gouge\Notifications\Channels\CompletionChannel;
use Webong\Gouge\Notifications\Channels\ContinuationChannel;

class ConversationTransportRouter
{
    public const Completion = 'completion';

    public const Continuation = 'continuation';

    public const Coordination = 'coordination';

    /**
     * @return array<int, class-string>
     */
    public function channelsFor(object $notifiable, Notification $notification): array
    {
        $transport = method_exists($notification, 'transportChannelName')
            ? $this->normalize($notification->transportChannelName($notifiable))
            : self::Coordination;

        return [match ($transport) {
            self::Completion => CompletionChannel::class,
            self::Continuation => ContinuationChannel::class,
            default => CoordinationChannel::class,
        }];
    }

    public function normalize(mixed $transport): string
    {
        if (! is_string($transport)) {
            return self::Coordination;
        }

        return match (strtolower(trim($transport))) {
            self::Completion => self::Completion,
            self::Continuation => self::Continuation,
            default => self::Coordination,
        };
    }
}
