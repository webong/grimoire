<?php

declare(strict_types=1);

$autoload = getenv('GRIMOIRE_LARAVEL_AUTOLOAD') ?: dirname(__DIR__, 6).'/host-app/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Set GRIMOIRE_LARAVEL_AUTOLOAD to a Laravel vendor/autoload.php file.\n");
    exit(2);
}

require $autoload;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Grimlock\\LaravelAi\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($path)) {
        require $path;
    }
});

use Grimlock\LaravelAi\Contracts\ControlPlaneClient;
use Grimlock\LaravelAi\Tools\InvokeAdmittedTool;
use Grimlock\LaravelAi\ValueObjects\AdmittedToolCall;
use Grimlock\LaravelAi\ValueObjects\AdmittedToolResult;
use Laravel\Ai\Tools\Request;

final class RecordingControlPlaneClient implements ControlPlaneClient
{
    public ?AdmittedToolCall $call = null;

    public function call(AdmittedToolCall $call): AdmittedToolResult
    {
        $this->call = $call;

        return AdmittedToolResult::approvalRequired('approval-1');
    }
}

$fixturePath = dirname(__DIR__, 3).'/conformance/v1alpha1/admission-approval-idempotency.fixture.json';
$fixture = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$write = $fixture['spec']['calls']['write'];
$client = new RecordingControlPlaneClient;
$tool = new InvokeAdmittedTool($client, 'session-1', 'run-1');
$response = json_decode($tool->handle(new Request([
    'tool_name' => $write['toolRef']['name'],
    'tool_version' => $write['toolRef']['version'],
    'arguments' => $write['arguments'],
], 'provider-call-1')), true, flags: JSON_THROW_ON_ERROR);

assert($response['ok'] === true);
assert($response['status'] === 'approval_required');
assert($response['approval_id'] === 'approval-1');
assert($client->call instanceof AdmittedToolCall);
assert($client->call->sessionId === 'session-1');
assert($client->call->runId === 'run-1');
assert($client->call->toolName === 'task.write');
assert($client->call->idempotencyKey === 'provider-call-1');

$invalid = json_decode($tool->handle(new Request(['tool_name' => 'task.write'])), true, flags: JSON_THROW_ON_ERROR);
assert($invalid['ok'] === false);
assert($invalid['error_code'] === 'invalid_request');

fwrite(STDOUT, "laravel-ai control-plane tool conformance passed\n");
