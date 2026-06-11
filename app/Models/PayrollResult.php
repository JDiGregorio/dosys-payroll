<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:4',
            'monthly_salary' => 'decimal:2',
            'biweekly_salary_amount' => 'decimal:2',
            'daily_rate' => 'decimal:4',
            'overtime_hourly_rate' => 'decimal:4',
            'worked_days' => 'decimal:2',
            'worked_hours' => 'decimal:2',
            'worked_salary_amount' => 'decimal:2',
            'regular_lost_amount' => 'decimal:2',
            'overtime_lost_amount' => 'decimal:2',
            'lost_time_amount' => 'decimal:2',
            'extra_bonuses_amount' => 'decimal:2',
            'referred_bonus_amount' => 'decimal:2',
            'adjustment_bonus_amount' => 'decimal:2',
            'base_salary_amount' => 'decimal:2',
            'absence_deduction' => 'decimal:2',
            'idle_deduction' => 'decimal:2',
            'overtime_amount' => 'decimal:2',
            'internet_subsidy_amount' => 'decimal:2',
            'qa_bonus_amount' => 'decimal:2',
            'productivity_bonus_amount' => 'decimal:2',
            'time_management_bonus_amount' => 'decimal:2',
            'payroll_compensation_amount' => 'decimal:2',
            'extras_total_amount' => 'decimal:2',
            'bonuses_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'private_insurance_amount' => 'decimal:2',
            'ihss_amount' => 'decimal:2',
            'isr_amount' => 'decimal:2',
            'rap_amount' => 'decimal:2',
            'vouchers_amount' => 'decimal:2',
            'total_deductions_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
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
