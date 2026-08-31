<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

final class Device extends GrimlockModel
{
    protected $table = 'devices';

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'endpoints' => 'array', 'last_seen_at' => 'immutable_datetime'];
    }
}
