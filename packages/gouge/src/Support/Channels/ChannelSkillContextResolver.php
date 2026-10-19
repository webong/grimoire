<?php

namespace Webong\Gouge\Support\Channels;

use App\Models\Server\Channel;
use App\Models\Server\ChannelAddress;
use App\Models\Server\ChannelRoute;
use App\Models\Server\Post;
use App\Models\Server\Space;
use App\Models\Server\Thread;
use Illuminate\Database\Eloquent\Model;
use Webong\Gouge\Ai\Support\Skills\SkillRepository;
use Webong\Gouge\Support\AgentArtifacts;

class ChannelSkillContextResolver
{
    public function __construct(protected SkillRepository $skillRepository) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        Channel $channel,
        ?ChannelRoute $route = null,
        ?ChannelAddress $address = null,
        ?Post $post = null,
        bool $includeContent = true,
    ): array {
        $context = $this->resolveContext($address?->addressable, $post);
        $channelSkills = $this->attachedEntries($channel, $includeContent);
        $spaceSkills = $context['space'] instanceof Space
            ? $this->attachedEntries($context['space'], $includeContent)
            : [];
        $threadSkills = $context['thread'] instanceof Thread
            ? $this->attachedEntries($context['thread'], $includeContent)
            : [];
        $postSkills = $post instanceof Post
            ? $this->attachedEntries($post, $includeContent)
            : [];

        return [
            'channel' => $channelSkills,
            'space' => $spaceSkills,
            'thread' => $threadSkills,
            'post' => $postSkills,
            'entries' => $this->uniqueEntries([
                ...$channelSkills,
                ...$spaceSkills,
                ...$threadSkills,
                ...$postSkills,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(
        Channel $channel,
        ?ChannelRoute $route = null,
        ?ChannelAddress $address = null,
        ?Post $post = null,
    ): array {
        $resolved = $this->resolve($channel, $route, $address, $post, includeContent: false);

        return [
            'entries' => $resolved['entries'],
            'counts' => [
                'channel' => count($resolved['channel']),
                'space' => count($resolved['space']),
                'thread' => count($resolved['thread']),
                'post' => count($resolved['post']),
                'total' => count($resolved['entries']),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function attachedEntries(Model $target, bool $includeContent): array
    {
        return Post::query()
            ->where('type', AgentArtifacts::SkillType)
            ->whereHas('relations', function ($query) use ($target): void {
                $query
                    ->where('role', AgentArtifacts::SkillRelation)
                    ->where('relationable_type', $target->getMorphClass())
                    ->where('relationable_id', $target->getKey());
            })
            ->latest('id')
            ->get()
            ->map(fn (Post $skill): array => $this->skillRepository->fromPost($skill, $includeContent))
            ->values()
            ->all();
    }

    /**
     * @return array{space: ?Space, thread: ?Thread}
     */
    protected function resolveContext(mixed $addressable, ?Post $post): array
    {
        $context = $post instanceof Post ? $post : $addressable;
        $space = null;
        $thread = null;
        $visited = [];

        while ($context instanceof Post || $context instanceof Thread) {
            $key = $context::class.':'.$context->getKey();

            if (isset($visited[$key])) {
                break;
            }

            $visited[$key] = true;

            if ($context instanceof Thread) {
                $thread ??= $context;
                $context = $context->threadable;

                continue;
            }

            $context = $context->postable;
        }

        if ($context instanceof Space) {
            $space = $context;
        } elseif ($addressable instanceof Space) {
            $space = $addressable;
        }

        if (! $thread instanceof Thread && $addressable instanceof Thread) {
            $thread = $addressable;
        }

        return [
            'space' => $space,
            'thread' => $thread,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    protected function uniqueEntries(array $entries): array
    {
        return collect($entries)
            ->unique(fn (array $entry): string => (string) ($entry['post_id'] ?? $entry['slug'] ?? 'unknown'))
            ->values()
            ->all();
    }
}
