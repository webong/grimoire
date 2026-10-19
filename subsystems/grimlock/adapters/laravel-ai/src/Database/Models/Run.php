<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

final class Run extends GrimlockModel
{
    protected $table = 'agent_runs';

    protected function casts(): array
    {
        return ['input' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
