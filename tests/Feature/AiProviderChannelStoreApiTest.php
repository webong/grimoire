<?php

namespace Webong\LaravelGouge\Tests\Feature;

use App\Models\Server\Channel;
use App\Models\Server\User;
use App\TokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Webong\LaravelGouge\Support\AgentChannels;

class AiProviderChannelStoreApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_ai_provider_channel_without_exposing_credentials(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, [TokenAbility::Compose->value]);

        $response = $this->postJson(route('api.channels.store'), [
            'owner_type' => 'user',
            'owner_id' => 'me',
            'protocol' => AgentChannels::AiProvider,
            'name' => 'review-provider',
            'label' => 'Review Provider',
            'transport' => Channel::TransportHttp,
            'endpoint_url' => 'https://models.example/v1',
            'credentials' => [
                'api_key' => 'secret-key',
            ],
            'config' => [
                'provider' => 'openai-compatible',
                'model' => 'review-model',
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.protocol', AgentChannels::AiProvider)
            ->assertJsonPath('data.config.provider', 'openai-compatible')
            ->assertJsonPath('data.config.model', 'review-model')
            ->assertJsonMissing(['api_key' => 'secret-key']);

        $channel = Channel::query()->where('server', 'review-provider')->firstOrFail();

        $this->assertSame('secret-key', $channel->credentials['api_key']);
    }
}
