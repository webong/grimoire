<?php

namespace Webong\Gouge\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webong\Gouge\Agents\RuntimeAgent;
use Webong\Gouge\Contracts\AgentExecutor;
use Webong\Gouge\Contracts\AgentFactory;
use Webong\Gouge\Contracts\AgentRuntime;
use Webong\Gouge\Contracts\RuntimeStore;
use Webong\Gouge\GougeServiceProvider;
use Webong\Gouge\Jobs\ExecuteAgentTurn;
use Webong\Gouge\Runtime\AgentRuntimeCorrelation;
use Webong\Gouge\Runtime\AgentTurn;
use Webong\Gouge\Runtime\ExecutionResult;
use Webong\Gouge\Runtime\LaravelAiRuntime;
use Webong\Gouge\Runtime\MessageCodec;
use Webong\Gouge\Storage\DatabaseRuntimeStore;

class RuntimeTest extends TestCase
{
    private Application $app;

    private Capsule $database;

    private RuntimeStore $store;

    private AgentRuntime $runtime;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__));
        $this->app->instance('config', new Repository(['gouge' => [
            'provider' => 'openai', 'model' => 'test-model',
            'definitions' => ['helper@1' => ['digest' => str_repeat('a', 64), 'instructions' => 'Answer the prompt.', 'tools' => []]],
        ]]));
        $this->app->instance('events', new Dispatcher($this->app));
        Facade::setFacadeApplication($this->app);
        $this->database = new Capsule($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->app->instance('db', $this->database->getDatabaseManager());
        $this->app->register(AiServiceProvider::class);
        $provider = $this->app->register(GougeServiceProvider::class);
        $provider->boot();
        $migration = require dirname(__DIR__).'/database/migrations/2026_10_19_000000_create_gouge_runtime_tables.php';
        $migration->up();
        $this->store = $this->app->make(RuntimeStore::class);
        $this->runtime = $this->app->make(AgentRuntime::class);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function turn(string $run = 'run-1', string $scope = 'scope-1', string $session = 'session-1', string $prompt = 'Hello', string $digest = ''): AgentTurn
    {
        return new AgentTurn($scope, $session, $run, 'helper', '1', $digest ?: str_repeat('a', 64), $prompt, ['external_id' => 'opaque']);
    }

    private function events(AgentTurn $turn): array
    {
        return iterator_to_array($this->runtime->streamEvents($this->runtime->correlationFor($turn)));
    }

    public function test_plain_laravel_boot_and_native_sdk_execution_require_no_consumer_tables(): void
    {
        RuntimeAgent::fake(['{"raw":"output"}'])->preventStrayPrompts();
        $turn = $this->turn();
        $this->runtime->submitTurn($turn);
        $events = $this->events($turn);
        $this->assertSame(['turn.accepted', 'turn.started', 'turn.output', 'turn.completed'], array_column($events, 'type'));
        $this->assertSame('{"raw":"output"}', $events[2]->payload['text']);
        $this->assertSame([0, 1, 2, 3], array_column($events, 'sequence'));
        $this->assertSame(['gouge_runs', 'gouge_sessions'], array_map(fn ($name) => str_replace('main.', '', $name), $this->database->getConnection()->getSchemaBuilder()->getTableListing()));
        $this->assertFalse($this->app->resolved('router'));
        $this->assertNull($this->app['config']->get('channels'));
        RuntimeAgent::assertPrompted('Hello');
    }

    public function test_identical_replay_does_not_invoke_model_again_and_conflicting_reuse_fails(): void
    {
        RuntimeAgent::fake(['one'])->preventStrayPrompts();
        $this->runtime->submitTurn($this->turn());
        $this->runtime->submitTurn($this->turn());
        $this->assertCount(4, $this->events($this->turn()));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different input');
        $this->runtime->submitTurn($this->turn(prompt: 'Changed'));
    }

    public function test_history_is_durable_and_scoped_to_session_and_consumer(): void
    {
        RuntimeAgent::fake(['first', 'second', 'other'])->preventStrayPrompts();
        $first = $this->turn();
        $this->runtime->submitTurn($first);
        $fresh = new DatabaseRuntimeStore($this->database->getConnection());
        $history = $fresh->history($this->runtime->correlationFor($first));
        $this->assertSame('Hello', $history[0]['content']);
        $this->assertSame('first', end($history)['content']);
        $agent = $this->app->make(AgentFactory::class)->make($this->turn('run-2'), $history);
        $messages = [...$agent->messages()];
        $this->assertSame('first', $messages[array_key_last($messages)]->content);
        $this->runtime->submitTurn($this->turn('run-2'));
        $this->runtime->submitTurn($this->turn(scope: 'scope-2'));
        $this->assertCount(2, $fresh->history(new AgentRuntimeCorrelation('scope-2', 'session-1', 'run-1')));
        $this->assertSame([], iterator_to_array($fresh->events(new AgentRuntimeCorrelation('scope-3', 'session-1', 'run-1'))));
        $this->assertFalse($fresh->cancel(new AgentRuntimeCorrelation('scope-3', 'session-1', 'run-1'))->accepted);
    }

    public function test_session_rejects_definition_changes_and_parallel_turns(): void
    {
        $this->assertTrue($this->store->reserve($this->turn()));
        try {
            $this->store->reserve($this->turn('run-2'));
            $this->fail('Busy session accepted another turn.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('active run', $error->getMessage());
        }
        $this->store->cancel($this->runtime->correlationFor($this->turn()));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different agent definition');
        $this->store->reserve($this->turn('run-2', digest: str_repeat('b', 64)));
    }

    public function test_missing_definition_fails_before_any_model_invocation(): void
    {
        RuntimeAgent::fake()->preventStrayPrompts();
        $turn = $this->turn(digest: str_repeat('b', 64));
        try {
            $this->runtime->submitTurn($turn);
            $this->fail('Unregistered digest was accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('not registered', $error->getMessage());
        }
        $this->assertSame(['turn.accepted', 'turn.failed'], array_column($this->events($turn), 'type'));
        RuntimeAgent::assertNeverPrompted();
    }

    public function test_cancel_before_start_never_executes_and_repeated_cancel_is_terminal(): void
    {
        $turn = $this->turn();
        $c = $this->runtime->correlationFor($turn);
        $this->store->reserve($turn);
        $this->assertTrue($this->runtime->cancel($c)->accepted);
        $this->assertFalse($this->store->start($c));
        $this->assertFalse($this->runtime->cancel($c)->accepted);
        $this->assertSame(['turn.accepted', 'turn.cancelled'], array_column($this->events($turn), 'type'));
    }

    public function test_in_flight_cancellation_waits_for_executor_and_discards_output(): void
    {
        $turn = $this->turn();
        $c = $this->runtime->correlationFor($turn);
        $executor = $this->createMock(AgentExecutor::class);
        $executor->expects($this->once())->method('execute')->willReturnCallback(function () use ($c) {
            $cancel = $this->runtime->cancel($c);
            $this->assertTrue($cancel->accepted);
            $this->assertFalse($cancel->interrupted);
            $events = iterator_to_array($this->store->events($c));
            $this->assertSame('turn.cancellation_requested', $events[array_key_last($events)]->type);

            return new ExecutionResult('late output');
        });
        $runtime = new LaravelAiRuntime($this->store, $this->app->make(AgentFactory::class), $executor);
        $runtime->submitTurn($turn);
        $types = array_column($this->events($turn), 'type');
        $this->assertSame(['turn.accepted', 'turn.started', 'turn.cancellation_requested', 'turn.cancelled'], $types);
        $this->assertSame([], $this->store->history($c));
    }

    public function test_execution_failure_is_durable_and_replay_does_not_repeat_effects(): void
    {
        $executor = $this->createMock(AgentExecutor::class);
        $executor->expects($this->once())->method('execute')->willThrowException(new RuntimeException('provider failed'));
        $runtime = new LaravelAiRuntime($this->store, $this->app->make(AgentFactory::class), $executor);
        try {
            $runtime->submitTurn($this->turn());
        } catch (RuntimeException $error) {
            $this->assertSame('provider failed', $error->getMessage());
        }
        $runtime->submitTurn($this->turn());
        $events = $this->events($this->turn());
        $this->assertSame('turn.failed', $events[array_key_last($events)]->type);
    }

    public function test_native_sdk_executes_an_explicit_tool_and_retains_its_transcript(): void
    {
        $tool = new Fixtures\CountingTool;
        $this->app->instance(Fixtures\CountingTool::class, $tool);
        $definitions = $this->app['config']->get('gouge.definitions');
        $definitions['helper@1']['tools'] = [Fixtures\CountingTool::class];
        $this->app['config']->set('gouge.definitions', $definitions);
        $runtime = $this->app->make(AgentRuntime::class);
        RuntimeAgent::fake([new ToolCall('call-1', 'lookup', []), 'The answer is 42.'])->preventStrayPrompts();
        $runtime->submitTurn($this->turn());
        $runtime->submitTurn($this->turn());
        $this->assertSame(1, $tool->calls);
        $history = $this->store->history($runtime->correlationFor($this->turn()));
        $toolMessages = array_values(array_filter($history, fn ($message) => $message['role'] === 'tool_result'));
        $this->assertSame('42', $toolMessages[0]['tool_results'][0]['result']);
        $this->assertSame('The answer is 42.', $this->events($this->turn())[2]->payload['text']);
    }

    public function test_queue_job_contains_only_runtime_input_and_duplicate_delivery_is_safe(): void
    {
        RuntimeAgent::fake(['done'])->preventStrayPrompts();
        $job = new ExecuteAgentTurn($this->turn());
        $restored = unserialize(serialize($job));
        $this->assertSame(1, $restored->tries);
        $this->assertEquals($job->turn, $restored->turn);
        $restored->handle($this->runtime);
        $restored->handle($this->runtime);
        $this->assertCount(4, $this->events($this->turn()));
    }

    public function test_completed_run_rejects_cancellation_without_changing_its_outcome(): void
    {
        RuntimeAgent::fake(['done'])->preventStrayPrompts();
        $this->runtime->submitTurn($this->turn());
        $this->assertFalse($this->runtime->cancel($this->runtime->correlationFor($this->turn()))->accepted);
        $this->assertSame('turn.completed', $this->events($this->turn())[3]->type);
    }

    public function test_other_session_cannot_read_or_cancel_the_run(): void
    {
        $this->store->reserve($this->turn());
        $wrong = new AgentRuntimeCorrelation('scope-1', 'another-session', 'run-1');
        $this->assertSame([], iterator_to_array($this->store->events($wrong)));
        $this->assertFalse($this->runtime->cancel($wrong)->accepted);
        $this->assertTrue($this->store->start($this->runtime->correlationFor($this->turn())));
    }

    public function test_runtime_state_survives_database_reconnection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gouge-state-');
        try {
            $this->database->addConnection(['driver' => 'sqlite', 'database' => $path], 'durable');
            $this->app['config']->set('gouge.connection', 'durable');
            $migration = require dirname(__DIR__).'/database/migrations/2026_10_19_000000_create_gouge_runtime_tables.php';
            $migration->up();
            RuntimeAgent::fake(['persisted'])->preventStrayPrompts();
            $runtime = $this->app->make(AgentRuntime::class);
            $runtime->submitTurn($this->turn());
            $this->database->getDatabaseManager()->purge('durable');
            $reconnected = $this->app->make(AgentRuntime::class);
            $reconnected->submitTurn($this->turn());
            $events = iterator_to_array($reconnected->streamEvents($reconnected->correlationFor($this->turn())));
            $this->assertCount(4, $events);
            $this->assertSame('persisted', $events[2]->payload['text']);
        } finally {
            $this->database->getDatabaseManager()->purge('durable');
            unlink($path);
        }
    }

    public function test_stranded_running_work_is_not_automatically_reexecuted(): void
    {
        RuntimeAgent::fake()->preventStrayPrompts();
        $turn = $this->turn();
        $this->store->reserve($turn);
        $this->store->start($this->runtime->correlationFor($turn));
        $this->app->make(AgentRuntime::class)->submitTurn($turn);
        RuntimeAgent::assertNeverPrompted();
        $this->assertSame(['turn.accepted', 'turn.started'], array_column($this->events($turn), 'type'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('active run');
        $this->runtime->submitTurn($this->turn('run-2'));
    }

    public function test_tool_transcript_round_trips_through_runtime_storage(): void
    {
        $call = new ToolCall('call-1', 'lookup', ['id' => 1]);
        $result = new ToolResult('call-1', 'lookup', ['id' => 1], ['value' => 42]);
        $messages = [new AssistantMessage('', collect([$call])), new ToolResultMessage(collect([$result]))];
        foreach ($messages as $message) {
            $serialized = json_decode(json_encode(MessageCodec::encode($message), JSON_THROW_ON_ERROR), true);
            $this->assertEquals($message, MessageCodec::decode($serialized));
        }
    }
}
