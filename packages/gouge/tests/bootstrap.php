<?php

$autoload = getenv('GOUGE_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$loader = require $autoload;
$loader->addPsr4('Webong\\Gouge\\', dirname(__DIR__).'/src');
$loader->addPsr4('Webong\\Gouge\\Tests\\', __DIR__);
if ($source = getenv('GOUGE_TEST_AI_SOURCE')) {
    $loader->addPsr4('Laravel\\Ai\\', $source.'/src', true);
    require_once $source.'/functions.php';
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\') || str_starts_with($class, 'Figurate\\')) {
        throw new LogicException('Consumer class requested by Gouge test: '.$class);
    }
}, prepend: true);
