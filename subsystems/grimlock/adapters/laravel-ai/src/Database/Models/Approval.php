<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

final class Approval extends GrimlockModel
{
    protected $table = 'agent_approvals';

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
