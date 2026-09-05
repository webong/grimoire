<?php

declare(strict_types=1);

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Webong\LaravelGouge\Tests\Behaviour\LaravelGougeFeatureContext;

$behaviourSuite = (new Suite('laravel_gouge'))
    ->withPaths('%paths.base%/tests/Behaviour/Features')
    ->withContexts(LaravelGougeFeatureContext::class);

return (new Config)
    ->withProfile(
        (new Profile('default'))
            ->withSuite($behaviourSuite),
    );
