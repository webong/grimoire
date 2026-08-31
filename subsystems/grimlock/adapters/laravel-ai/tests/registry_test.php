<?php

declare(strict_types=1);

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

use Grimlock\LaravelAi\Support\DefaultConnectorInvocationPolicy;
use Grimlock\LaravelAi\Support\InMemoryRegistry;
use Grimlock\LaravelAi\ValueObjects\ManagedClient;
use Grimlock\LaravelAi\ValueObjects\ManagedConnector;
use Grimlock\LaravelAi\ValueObjects\ManagedDevice;
use Grimlock\LaravelAi\ValueObjects\ManagedSkill;

$registry = new InMemoryRegistry;
$registry->upsert(new ManagedClient('client-1', 'desktop', 'device-1'));
$registry->upsert(new ManagedDevice('browser-1', 'browser', 'Work browser', 'client-1', ['dom.read']));
$connector = new ManagedConnector(
    'mcp-1', 'mcp', 'http', ManagedConnector::DIRECTION_BIDIRECTIONAL,
    ManagedConnector::STATUS_ACTIVE, ['search'], endpoint: 'https://mcp.example.test', deviceId: 'browser-1',
);
$registry->upsert($connector);
$registry->addSkill(new ManagedSkill('research-1', 'research', 'Research with sources.', 'skill://research', 'sha256:abc'));

assert(count($registry->clients()) === 1);
assert(count($registry->devices()) === 1);
assert(count($registry->available()) === 1);
assert(count($registry->discover('source')) === 1);
assert((new DefaultConnectorInvocationPolicy)->authorize($connector, 'search')->allowed === true);
assert((new DefaultConnectorInvocationPolicy)->authorize($connector, 'delete')->allowed === false);

fwrite(STDOUT, "laravel-ai registry conformance passed\n");
