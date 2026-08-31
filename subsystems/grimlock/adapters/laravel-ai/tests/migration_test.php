<?php

declare(strict_types=1);

$autoload = getenv('GRIMOIRE_LARAVEL_AUTOLOAD') ?: dirname(__DIR__, 6).'/figurate/vendor/autoload.php';

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

use Grimlock\LaravelAi\Support\EloquentRegistry;
use Grimlock\LaravelAi\ValueObjects\ManagedClient;
use Grimlock\LaravelAi\ValueObjects\ManagedConnector;
use Grimlock\LaravelAi\ValueObjects\ManagedDevice;
use Grimlock\LaravelAi\ValueObjects\ManagedSkill;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

$databasePath = $argv[1] ?? tempnam(sys_get_temp_dir(), 'grimoire-').'.sqlite';

if (! is_file($databasePath)) {
    touch($databasePath);
}

$container = new Container;
$capsule = new Capsule($container);
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => $databasePath,
    'foreign_key_constraints' => true,
]);
$capsule->setEventDispatcher(new Dispatcher($container));
$capsule->setAsGlobal();
$capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', static fn () => $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);

$migration = require __DIR__.'/../database/migrations/2026_08_27_000000_create_core_tables.php';
$migration->up();

$tables = ['resources', 'clients', 'devices', 'connectors', 'sessions', 'runs', 'approvals', 'events', 'idempotency_records'];

foreach ($tables as $table) {
    assert(Schema::hasTable($table), "Missing table: {$table}");
}

assert(! Schema::hasTable('grimlock_resources'));
assert(! Schema::hasColumn('resources', 'tenant_id'));

$registry = new EloquentRegistry;
$registry->upsert(new ManagedClient('01JCLIENT00000000000000001', 'desktop', 'figurate-test-client'));
$registry->upsert(new ManagedDevice('01JDEVICE00000000000000001', 'browser', 'Test browser', '01JCLIENT00000000000000001', ['dom.read']));
$registry->upsert(new ManagedConnector(
    '01JCONNECTOR000000000000001',
    'mcp',
    'http',
    ManagedConnector::DIRECTION_BIDIRECTIONAL,
    ManagedConnector::STATUS_ACTIVE,
    ['search'],
    endpoint: 'https://example.invalid/mcp',
    deviceId: '01JDEVICE00000000000000001',
));
$registry->addSkill(new ManagedSkill('01JSKILL000000000000000001', 'research', 'Research with sources.', 'skill://research', str_repeat('a', 64)));

assert(count($registry->clients()) === 1);
assert(count($registry->devices()) === 1);
assert(count($registry->available()) === 1);
assert(count($registry->discover('research')) === 1);

fwrite(STDOUT, "Illuminate migration and Eloquent registry passed: {$databasePath}\n");
