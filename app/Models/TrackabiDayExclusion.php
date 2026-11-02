<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackabiDayExclusion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'archived_insights' => 'array',
        ];
    }
}
