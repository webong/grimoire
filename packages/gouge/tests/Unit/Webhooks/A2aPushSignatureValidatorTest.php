<?php

namespace Webong\Gouge\Tests\Unit\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\WebhookConfig;
use Spatie\WebhookClient\WebhookProfile\ProcessEverythingWebhookProfile;
use Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo;
use Tests\TestCase;
use Webong\Gouge\Jobs\ProcessInboundA2aPushWebhookJob;
use Webong\Gouge\Support\Webhooks\A2aPushSignatureValidator;

class A2aPushSignatureValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('a2a.inbound.push_notifications.timestamp_header_name', 'Timestamp');
        config()->set('a2a.inbound.push_notifications.max_skew_seconds', 300);
        config()->set('a2a.inbound.push_notifications.replay_ttl_seconds', 600);
    }

    public function test_it_accepts_a_fresh_signed_request_once(): void
    {
        $validator = app(A2aPushSignatureValidator::class);
        $request = $this->makeRequest(['task' => ['id' => 'remote-task-1']], now()->toIso8601String(), 'secret');

        $this->assertTrue($validator->isValid($request, $this->webhookConfig('secret')));
    }

    public function test_it_rejects_stale_signed_requests(): void
    {
        $validator = app(A2aPushSignatureValidator::class);
        $request = $this->makeRequest(['task' => ['id' => 'remote-task-1']], now()->subMinutes(10)->toIso8601String(), 'secret');

        $this->assertFalse($validator->isValid($request, $this->webhookConfig('secret')));
    }

    public function test_it_rejects_replayed_signed_requests(): void
    {
        $validator = app(A2aPushSignatureValidator::class);
        $request = $this->makeRequest(['task' => ['id' => 'remote-task-1']], now()->toIso8601String(), 'secret');
        $config = $this->webhookConfig('secret');

        $this->assertTrue($validator->isValid($request, $config));
        $this->assertFalse($validator->isValid($request, $config));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function makeRequest(array $payload, string $timestamp, string $secret): Request
    {
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $content, $secret);

        return Request::create(
            uri: '/webhooks/push',
            method: 'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_SIGNATURE' => $signature,
                'HTTP_TIMESTAMP' => $timestamp,
                'HTTP_X_A2A_REMOTE_AGENT' => 'planner',
            ],
            content: $content,
        );
    }

    protected function webhookConfig(string $secret): WebhookConfig
    {
        return new WebhookConfig([
            'name' => 'a2a_push',
            'signing_secret' => $secret,
            'signature_header_name' => 'Signature',
            'signature_validator' => A2aPushSignatureValidator::class,
            'webhook_profile' => ProcessEverythingWebhookProfile::class,
            'webhook_response' => DefaultRespondsTo::class,
            'webhook_model' => WebhookCall::class,
            'store_headers' => ['*'],
            'process_webhook_job' => ProcessInboundA2aPushWebhookJob::class,
        ]);
    }
}
