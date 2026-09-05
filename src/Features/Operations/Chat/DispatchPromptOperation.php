<?php

namespace Webong\LaravelGouge\Features\Operations\Chat;

use App\Features\Actions\Chat\DispatchThreadMessage;
use App\Features\Actions\Chat\EnsureThreadMembership;
use App\Features\Actions\Chat\ThreadMessageEntry;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Illuminate\Support\Collection;
use Webong\LaravelGouge\Features\Actions\Chat\EnsureThreadPresenter;
use Webong\LaravelGouge\Features\Actions\Chat\ResolveActiveThreadPresenters;

class DispatchPromptOperation
{
    public function __construct(
        protected DispatchThreadMessage $dispatchThreadMessage,
        protected EnsureThreadMembership $ensureThreadMembership,
        protected EnsureThreadPresenter $ensureThreadPresenter,
        protected ResolveActiveThreadPresenters $resolveActiveThreadPresenters,
    ) {}

    /**
     * @param  array{
     *     direct_source?: string,
     *     agent_source?: string,
     *     dispatch_observers_when_direct?: bool,
     *     dispatch_observers_when_agent?: bool,
     *     ensure_membership?: bool,
     *     ensure_presenter?: bool,
     *     presenter_actor_type?: string|null,
     *     meta?: array<string, mixed>,
     *     actions?: array<int, array<string, mixed>>|null,
     *     errors?: array<int, array<string, mixed>>|null,
     *     broadcast_space_id?: string|null
     * }  $options
     * @return array{
     *     message: Post,
     *     presenters: Collection<int, ThreadActor>,
     *     direct: bool,
     *     broadcast_space_id: string
     * }
     */
    public function run(Space $space, Thread $thread, User $actor, string $text, array $options = []): array
    {
        if (($options['ensure_membership'] ?? false) === true) {
            $this->ensureThreadMembership->execute($thread, $actor);
        }

        $presenters = $this->resolveActiveThreadPresenters->execute($thread);
        if (($options['ensure_presenter'] ?? false) === true && $presenters->isEmpty()) {
            $presenterActorType = $options['presenter_actor_type'] ?? null;
            if (is_string($presenterActorType) && $presenterActorType !== '') {
                $this->ensureThreadPresenter->execute($thread, $presenterActorType);
                $presenters = $this->resolveActiveThreadPresenters->execute($thread);
            }
        }

        $direct = $presenters->isEmpty();
        $source = $direct
            ? (is_string($options['direct_source'] ?? null) ? $options['direct_source'] : 'peer_message')
            : (is_string($options['agent_source'] ?? null) ? $options['agent_source'] : 'agent_prompt');
        $dispatchObservers = $direct
            ? (bool) ($options['dispatch_observers_when_direct'] ?? true)
            : (bool) ($options['dispatch_observers_when_agent'] ?? false);
        $broadcastSpaceId = is_string($options['broadcast_space_id'] ?? null) && $options['broadcast_space_id'] !== ''
            ? $options['broadcast_space_id']
            : "threads.{$thread->uuid}";

        $message = $this->dispatchThreadMessage->execute(ThreadMessageEntry::peerMessage(
            space: $space,
            thread: $thread,
            actor: $actor,
            text: $text,
            attachments: collect(),
            source: $source,
            meta: [
                ...(is_array($options['meta'] ?? null) ? $options['meta'] : []),
                'observer_dispatch' => $dispatchObservers,
            ],
        ));

        $updates = [];
        $data = is_array($message->data) ? $message->data : [];

        if (is_array($options['actions'] ?? null) && $options['actions'] !== []) {
            $data['actions'] = array_values($options['actions']);
        }
        if (is_array($options['errors'] ?? null) && $options['errors'] !== []) {
            $data['errors'] = array_values($options['errors']);
        }
        if ($data !== []) {
            $updates['data'] = $data;
        }

        if ($updates !== []) {
            $message->forceFill($updates)->save();
        }

        return [
            'message' => $message,
            'presenters' => $presenters,
            'direct' => $direct,
            'broadcast_space_id' => $broadcastSpaceId,
        ];
    }
}
