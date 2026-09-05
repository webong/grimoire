<?php

namespace Webong\LaravelGouge\Ai\Tools;

use App\Models\Server\Post;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Throwable;
use Webong\LaravelGouge\Ai\Agents\ObserverAgent;
use Webong\LaravelGouge\Ai\Support\Observer\Contracts\ObserverSkill;
use Webong\LaravelGouge\Ai\Support\Observer\ObserverResult;

class SafetyGuardObserver implements ObserverSkill
{
    public function __construct(
        protected Thread $thread,
        protected Post $message,
        protected ThreadActor $threadActor,
        protected array $skill = [],
    ) {}

    public function observe(): ?ObserverResult
    {
        try {
            $response = ObserverAgent::make(
                thread: $this->thread,
                message: $this->message,
                threadActor: $this->threadActor,
            )->prompt($this->buildPrompt());

            return $this->normalizeAgentResponse(is_array($response) ? $response : []);
        } catch (Throwable) {
            return $this->fallbackKeywordRules($this->message);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function normalizeAgentResponse(array $response): ?ObserverResult
    {
        $action = mb_strtolower(trim((string) ($response['action'] ?? 'allow')));
        $reason = trim((string) ($response['reason'] ?? ''));
        $suggestion = trim((string) ($response['suggestion'] ?? ''));
        $severity = $this->normalizeSeverity(
            mb_strtolower(trim((string) ($response['severity'] ?? 'low'))),
            'low',
        );

        return match ($action) {
            'block' => $this->result(
                eventType: 'message_blocked',
                severity: $this->normalizeSeverity($severity, 'high'),
                payload: ['reason' => $reason, 'source' => $this->eventSource()],
                redactMessage: true,
            ),
            'flag' => $this->result(
                eventType: 'moderation_flagged',
                severity: $this->normalizeSeverity($severity, 'medium'),
                payload: ['reason' => $reason, 'source' => $this->eventSource()],
            ),
            'suggest' => $this->result(
                eventType: 'suggestion_created',
                severity: $this->normalizeSeverity($severity, 'low'),
                payload: [
                    'reason' => $reason,
                    'suggestion' => $suggestion,
                    'source' => $this->eventSource(),
                ],
            ),
            default => null,
        };
    }

    protected function fallbackKeywordRules(Post $message): ?ObserverResult
    {
        $content = mb_strtolower((string) $message->text);

        foreach ($this->blockedKeywords() as $keyword) {
            if (str_contains($content, $keyword)) {
                return $this->result(
                    eventType: 'message_blocked',
                    severity: 'high',
                    payload: ['reason' => 'sensitive_term', 'term' => $keyword, 'source' => 'fallback_keyword'],
                    redactMessage: true,
                );
            }
        }

        foreach ($this->flaggedKeywords() as $keyword) {
            if (str_contains($content, $keyword)) {
                return $this->result(
                    eventType: 'moderation_flagged',
                    severity: 'medium',
                    payload: ['reason' => 'risky_term', 'term' => $keyword, 'source' => 'fallback_keyword'],
                );
            }
        }

        return null;
    }

    protected function normalizeSeverity(string $severity, string $default): string
    {
        return in_array($severity, ['low', 'medium', 'high'], true) ? $severity : $default;
    }

    protected function buildPrompt(): string
    {
        $attachments = collect($this->message->attachments ?? [])
            ->map(fn (mixed $item): string => is_array($item) ? ($item['name'] ?? 'file') : 'file')
            ->values()
            ->all();

        return json_encode([
            'thread' => [
                'id' => $this->thread->id,
                'purpose' => $this->thread->purpose,
                'phase' => $this->thread->phase,
            ],
            'observer_actor' => $this->threadActor->actorReference(),
            'message' => [
                'id' => $this->message->id,
                'body' => $this->message->text,
                'attachments' => $attachments,
            ],
        ], JSON_PRETTY_PRINT) ?: (string) $this->message->text;
    }

    /**
     * @return list<string>
     */
    protected function blockedKeywords(): array
    {
        $configured = data_get($this->skill, 'rules.blocked_keywords');

        if (is_array($configured)) {
            $keywords = collect($configured)
                ->filter(fn (mixed $keyword): bool => is_string($keyword) && trim($keyword) !== '')
                ->map(fn (string $keyword): string => mb_strtolower(trim($keyword)))
                ->values()
                ->all();

            if ($keywords !== []) {
                return $keywords;
            }
        }

        return [
            'credit card',
            'card number',
            'cvv',
            'otp',
            'one time password',
            'social security number',
        ];
    }

    /**
     * @return list<string>
     */
    protected function flaggedKeywords(): array
    {
        $configured = data_get($this->skill, 'rules.flagged_keywords');

        if (is_array($configured)) {
            $keywords = collect($configured)
                ->filter(fn (mixed $keyword): bool => is_string($keyword) && trim($keyword) !== '')
                ->map(fn (string $keyword): string => mb_strtolower(trim($keyword)))
                ->values()
                ->all();

            if ($keywords !== []) {
                return $keywords;
            }
        }

        return [
            'whatsapp',
            'telegram',
            'pay outside',
            'off platform',
            'bank transfer',
            'send money directly',
        ];
    }

    protected function eventSource(): string
    {
        $source = data_get($this->skill, 'source');

        if (! is_string($source) || trim($source) === '') {
            return 'observer_agent';
        }

        return trim($source);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function result(
        string $eventType,
        string $severity,
        ?array $payload = null,
        bool $redactMessage = false,
    ): ObserverResult {
        return new ObserverResult(
            eventType: $eventType,
            severity: $severity,
            payload: $payload,
            redactMessage: $redactMessage,
        );
    }
}
