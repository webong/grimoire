<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

final class Session extends GrimlockModel
{
    protected $table = 'sessions';

    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}
