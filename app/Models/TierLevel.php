<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TierLevel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weekly_hours' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    public function scheduleType(): BelongsTo
    {
        return $this->belongsTo(ScheduleType::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
