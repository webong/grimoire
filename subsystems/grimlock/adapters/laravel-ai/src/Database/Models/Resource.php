<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

final class Resource extends GrimlockModel
{
    protected $table = 'catalog_entries';

    protected function casts(): array
    {
        return ['spec' => 'array'];
    }
}
