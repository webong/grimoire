<?php

namespace Webong\LaravelGouge\Tests\Behaviour;

use App\Models\Server\Space;
use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\OpenAiProvider;
use Tests\Behaviour\Contexts\FeatureContext;
use Webong\LaravelGouge\Support\AgentActors;

class LaravelGougeFeatureContext extends FeatureContext
{
    protected ?string $originalAiProvider = null;

    #[AfterScenario]
    public function finishAgentScenario(): void
    {
        if ($this->originalAiProvider === null) {
            return;
        }

        $this->application()->make('config')->set('ai.default', $this->originalAiProvider);
        $this->application()->make(AiManager::class)->forgetInstance('behaviour');
        $this->originalAiProvider = null;
    }

    #[Given('an accessible automated thread exists')]
    public function anAccessibleAutomatedThreadExists(): void
    {
        $this->anAccessibleSpaceExistsAs('space_id');

        $space = Space::query()
            ->where('uuid', $this->memory['space_id'])
            ->firstOrFail();
        $thread = $space->threads()->create([
            'title' => 'Automated contract review',
            'purpose' => Thread::PurposeMain,
            'phase' => Thread::PhaseInitial,
            'status' => 'open',
        ]);
        $thread->actors()->create([
            'actorable_type' => AgentActors::Coordinator,
            'actorable_id' => null,
            'role' => AgentActors::RolePresenter,
            'status' => ThreadActor::StatusActive,
            'priority' => 1,
        ]);

        $this->memory['thread_id'] = $thread->uuid;
    }

    #[Given('the deterministic AI provider responds with:')]
    public function theDeterministicAiProviderRespondsWith(PyStringNode $response): void
    {
        $application = $this->application();
        $config = $application->make('config');
        $this->originalAiProvider ??= (string) $config->get('ai.default');
        $config->set('ai.default', 'behaviour');
        $config->set('ai.providers.behaviour', [
            'driver' => 'behaviour',
            'key' => 'behaviour-test-key',
        ]);

        $manager = $application->make(AiManager::class);
        $manager->extend(
            'behaviour',
            function (Application $application, array $providerConfig) use ($response): OpenAiProvider {
                $gateway = (new FakeTextGateway([$response->getRaw()]))
                    ->preventStrayPrompts();

                return (new OpenAiProvider(
                    new OpenAiGateway($application->make(Dispatcher::class)),
                    $providerConfig,
                    $application->make(Dispatcher::class),
                ))->useTextGateway($gateway);
            },
        );
        $manager->forgetInstance('behaviour');
    }
}
