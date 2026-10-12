<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackabiActivityImportBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'metadata' => 'array',
            'imported_at' => 'datetime',
        ];
    }
}
