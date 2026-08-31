<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

use Illuminate\Database\Eloquent\Model;

final class Event extends Model
{
    protected $table = 'events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
