<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaidTimeProject extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'requires_review' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
