<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Contracts;

use Grimlock\LaravelAi\ValueObjects\AdmittedToolCall;
use Grimlock\LaravelAi\ValueObjects\AdmittedToolResult;

// A host supplies this transport adapter. It must submit calls to Grimlock's
// control-plane API instead of dispatching an ungoverned local executor.
interface ControlPlaneClient
{
    public function call(AdmittedToolCall $call): AdmittedToolResult;
}
