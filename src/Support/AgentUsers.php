<?php

namespace Webong\LaravelGouge\Support;

use App\Models\Server\User;

final class AgentUsers
{
    public const Robot = 'robot';

    public static function isRobot(User $user): bool
    {
        return $user->type === self::Robot;
    }
}
