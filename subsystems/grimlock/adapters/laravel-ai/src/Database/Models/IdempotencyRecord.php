<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

final class IdempotencyRecord extends GrimlockModel
{
    protected $table = 'agent_idempotency_records';

    protected function casts(): array
    {
        return ['output' => 'array'];
    }
}
