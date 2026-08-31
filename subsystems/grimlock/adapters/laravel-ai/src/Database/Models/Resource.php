<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

final class Resource extends GrimlockModel
{
    protected $table = 'resources';

    protected function casts(): array
    {
        return ['spec' => 'array'];
    }
}
