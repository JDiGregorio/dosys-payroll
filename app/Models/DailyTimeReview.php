<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyTimeReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'activity_percentage' => 'decimal:2',
            'idle_percentage' => 'decimal:2',
            'assigned_overtime_fulfilled' => 'boolean',
            'paid_day_off' => 'boolean',
        ];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function overtimeRateType(): BelongsTo
    {
        return $this->belongsTo(HourlyRateType::class, 'overtime_rate_type_id');
    }
}
