<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Database\Models;

use Illuminate\Database\Eloquent\Model;

abstract class GrimlockModel extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
