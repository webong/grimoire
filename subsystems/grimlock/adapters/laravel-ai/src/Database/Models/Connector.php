<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

final class Connector extends GrimlockModel
{
    protected $table = 'agent_connectors';

    protected function casts(): array
    {
        return [
            'allowed_operations' => 'array',
            'metadata' => 'array',
            'last_connected_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
