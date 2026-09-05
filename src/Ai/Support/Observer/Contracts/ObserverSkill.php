<?php

namespace Webong\LaravelGouge\Ai\Support\Observer\Contracts;

use Webong\LaravelGouge\Ai\Support\Observer\ObserverResult;

interface ObserverSkill
{
    public function observe(): ?ObserverResult;
}
