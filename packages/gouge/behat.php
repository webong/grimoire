<?php

declare(strict_types=1);

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Webong\Gouge\Tests\Behaviour\GougeFeatureContext;

$behaviourSuite = (new Suite('gouge'))
    ->withPaths('%paths.base%/tests/Behaviour/Features')
    ->withContexts(GougeFeatureContext::class);

return (new Config)
    ->withProfile(
        (new Profile('default'))
            ->withSuite($behaviourSuite),
    );
