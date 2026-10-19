<?php

namespace Webong\Gouge\Support\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookClient\SignatureValidator\DefaultSignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

class A2aPushSignatureValidator extends DefaultSignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        if (! parent::isValid($request, $config)) {
            $this->logDeniedRequest($request, 'invalid_signature');

            return false;
        }

        $timestampHeaderName = (string) config(
            'a2a.inbound.push_notifications.timestamp_header_name',
            config('webhook-server.timestamp_header_name', 'Timestamp')
        );
        $timestamp = $request->header($timestampHeaderName);

        if (! is_string($timestamp) || trim($timestamp) === '') {
            $this->logDeniedRequest($request, 'missing_timestamp');

            return false;
        }

        try {
            $parsedTimestamp = CarbonImmutable::parse(trim($timestamp));
        } catch (\Throwable) {
            $this->logDeniedRequest($request, 'invalid_timestamp');

            return false;
        }

        $maxSkewSeconds = max(1, (int) config('a2a.inbound.push_notifications.max_skew_seconds', 300));
        $driftSeconds = abs(now()->getTimestamp() - $parsedTimestamp->getTimestamp());

        if ($driftSeconds > $maxSkewSeconds) {
            $this->logDeniedRequest($request, 'stale_timestamp', [
                'drift_seconds' => $driftSeconds,
            ]);

            return false;
        }

        $signature = (string) $request->header($config->signatureHeaderName, '');
        $bodyHash = hash('sha256', $request->getContent());
        $replayCacheKey = 'webhooks:a2a_push:'.hash('sha256', "{$signature}|{$timestamp}|{$bodyHash}");
        $replayTtlSeconds = max($maxSkewSeconds, (int) config('a2a.inbound.push_notifications.replay_ttl_seconds', 600));

        if (! Cache::add($replayCacheKey, now()->toIso8601String(), now()->addSeconds($replayTtlSeconds))) {
            $this->logDeniedRequest($request, 'replay_detected');

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logDeniedRequest(Request $request, string $reason, array $context = []): void
    {
        Log::warning('Denied inbound A2A push webhook request.', [
            'reason' => $reason,
            'path' => $request->path(),
            'ip' => $request->ip(),
            'remote_agent_id' => $request->header('X-A2A-Remote-Agent'),
            ...$context,
        ]);
    }
}
