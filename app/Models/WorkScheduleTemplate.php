<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkScheduleTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkScheduleTemplateDay::class)->orderBy('day_number');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
