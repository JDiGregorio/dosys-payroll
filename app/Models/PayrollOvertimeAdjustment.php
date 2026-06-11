<?php

namespace App\Models;

use App\Services\PayrollCalculationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollOvertimeAdjustment extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $recalculate = function (PayrollOvertimeAdjustment $adjustment): void {
            $period = PayrollPeriod::query()->find($adjustment->payroll_period_id);
            $employee = Employee::query()->find($adjustment->employee_id);

            if ($period && $employee) {
                app(PayrollCalculationService::class)->recalculateEmployeePayrollResult($period, $employee);
            }
        };

        static::saved($recalculate);
        static::deleted($recalculate);
    }

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
