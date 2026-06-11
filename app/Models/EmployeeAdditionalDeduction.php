<?php

namespace App\Models;

use App\Services\PayrollCalculationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAdditionalDeduction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $recalculate = function (EmployeeAdditionalDeduction $deduction): void {
            $period = PayrollPeriod::query()->find($deduction->payroll_period_id);

            if ($period) {
                app(PayrollCalculationService::class)->recalculatePayrollResults($period);
            }
        };

        static::saved($recalculate);
        static::deleted($recalculate);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }
}
