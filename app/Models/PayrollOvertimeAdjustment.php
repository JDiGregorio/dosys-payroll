<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollOvertimeAdjustment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'active' => 'boolean',
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
}
