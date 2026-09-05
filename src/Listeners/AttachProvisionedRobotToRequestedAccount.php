<?php

namespace Webong\LaravelGouge\Listeners;

use App\Models\Server\User;
use Webong\LaravelGouge\Events\Server\Auth\RobotProvisioned;
use Webong\LaravelGouge\Support\AccountManager\AttachRobotUserToRequestedAccount;

class AttachProvisionedRobotToRequestedAccount
{
    public function __construct(protected AttachRobotUserToRequestedAccount $attachRobotUserToRequestedAccount) {}

    public function handle(RobotProvisioned $event): void
    {
        if (
            ! $event->creator instanceof User
            || ! $event->robot instanceof User
            || $event->requestedAccountUuid === null
            || trim($event->requestedAccountUuid) === ''
        ) {
            return;
        }

        ($this->attachRobotUserToRequestedAccount)(
            $event->robot,
            $event->creator,
            $event->requestedAccountUuid,
        );
    }
}
