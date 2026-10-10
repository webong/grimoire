<?php

namespace Webong\Gouge\Ai\Support\Observer\Contracts;

use Webong\Gouge\Ai\Support\Observer\ObserverResult;

interface ObserverSkill
{
    public function observe(): ?ObserverResult;
}
