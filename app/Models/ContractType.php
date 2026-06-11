<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_weekly_hours' => 'decimal:2',
            'max_weekly_hours' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
