<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HourlyRateType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'multiplier' => 'decimal:4',
            'active' => 'boolean',
        ];
    }
}
