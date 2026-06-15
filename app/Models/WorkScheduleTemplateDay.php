<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleTemplateDay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_working_day' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleTemplate::class, 'work_schedule_template_id');
    }

    protected function expectedHours(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((int) $this->expected_seconds / 3600, 2),
            set: fn (mixed $value): array => [
                'expected_seconds' => max((int) round((float) $value * 3600), 0),
            ],
        );
    }
}
