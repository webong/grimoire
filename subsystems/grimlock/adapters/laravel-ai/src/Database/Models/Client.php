<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

final class Client extends GrimlockModel
{
    protected $table = 'clients';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'last_seen_at' => 'immutable_datetime'];
    }
}
