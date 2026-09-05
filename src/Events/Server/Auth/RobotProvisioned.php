<?php

namespace Webong\LaravelGouge\Events\Server\Auth;

use App\Models\Server\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RobotProvisioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $creator,
        public User $robot,
        public ?string $requestedAccountUuid = null,
    ) {}
}
