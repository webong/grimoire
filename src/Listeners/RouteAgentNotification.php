<?php

namespace Webong\Gouge\Listeners;

use App\Events\Server\Notifications\RoutingNotificationChannels;
use App\Models\Server\Post;
use App\Models\Server\User;
use App\Notifications\Channels\CoordinationChannel;
use Figurate\Auth\Support\RobotUsers;
use Webong\Gouge\Ai\Storage\ConversationPersistenceResolver;
use Webong\Gouge\Notifications\Channels\CompletionChannel;
use Webong\Gouge\Notifications\Channels\ContinuationChannel;
use Webong\Gouge\Notifications\Channels\ThreadCoordinationChannel;

class RouteAgentNotification
{
    public function handle(RoutingNotificationChannels $event): void
    {
        if (! $event->notifiable instanceof User || ! RobotUsers::isRobot($event->notifiable)) {
            return;
        }

        if (! method_exists($event->notification, 'toInbox')) {
            return;
        }

        $post = $event->notification->toInbox($event->notifiable);
        if (! $post instanceof Post) {
            return;
        }

        $event->channels = match (ConversationPersistenceResolver::normalizeMode(
            data_get($post->meta, 'conversation_persistence')
        )) {
            ConversationPersistenceResolver::ThreadCompletion => [CompletionChannel::class],
            ConversationPersistenceResolver::ThreadContinuation => [ContinuationChannel::class],
            default => [CoordinationChannel::class, ThreadCoordinationChannel::class],
        };
    }
}
