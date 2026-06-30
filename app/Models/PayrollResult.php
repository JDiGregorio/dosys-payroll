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
            'scheduled_days' => 'decimal:2',
            'worked_hours' => 'decimal:2',
            'worked_salary_amount' => 'decimal:2',
            'regular_lost_amount' => 'decimal:2',
            'overtime_lost_amount' => 'decimal:2',
            'lost_time_amount' => 'decimal:2',
            'extra_bonuses_amount' => 'decimal:2',
            'referred_bonus_amount' => 'decimal:2',
            'adjustment_bonus_amount' => 'decimal:2',
            'tier_adjustment_bonus_amount' => 'decimal:2',
            'vacation_bonus_amount' => 'decimal:2',
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
            'tier_adjustment_deduction_amount' => 'decimal:2',
            'other_deductions_amount' => 'decimal:2',
            'total_deductions_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'voucher_queued_at' => 'datetime',
            'voucher_sent_at' => 'datetime',
            'voucher_failed_at' => 'datetime',
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

    public function displayWorkedDays(): float
    {
        if ($this->shouldDisplayFixedBiweeklyDays()) {
            return round(max(15.0 - $this->displayLostDays(), 0), 2);
        }

        return round((float) $this->worked_days, 2);
    }

    public function voucherDeliveryStatus(): string
    {
        if ($this->voucher_delivery_status === 'sent' && $this->voucher_sent_at) {
            $sentAt = $this->voucher_sent_at->format('d/m/Y H:i');
            $sentTo = trim((string) $this->voucher_sent_to);

            return $sentTo !== ''
                ? "Enviado {$sentAt} a {$sentTo}"
                : "Enviado {$sentAt}";
        }

        if ($this->voucher_delivery_status === 'failed') {
            return 'Falló el envío';
        }

        if ($this->voucher_delivery_status === 'queued') {
            return 'En cola para envío';
        }

        return 'Pendiente';
    }

    private function displayLostDays(): float
    {
        $lostAmount = (float) $this->lost_time_amount;
        $dailyRate = (float) $this->daily_rate;

        if ($lostAmount > 0 && $dailyRate > 0) {
            return $lostAmount / $dailyRate;
        }

        $lostSeconds = (int) $this->lost_time_seconds;

        if ($lostSeconds <= 0) {
            return 0.0;
        }

        $employee = $this->employeeForDisplay();
        $dailyHours = (float) $employee?->daily_hours;

        return $dailyHours > 0
            ? $lostSeconds / ($dailyHours * 3600)
            : 0.0;
    }

    private function shouldDisplayFixedBiweeklyDays(): bool
    {
        $employee = $this->employeeForDisplay();
        $scheduleType = $employee?->relationLoaded('scheduleType')
            ? $employee->scheduleType
            : $employee?->scheduleType()->first();

        return $scheduleType?->code === 'rotativa'
            || $this->salary_calculation_method === 'semi_monthly_fixed_with_deductions';
    }

    private function employeeForDisplay(): ?Employee
    {
        return $this->relationLoaded('employee')
            ? $this->employee
            : $this->employee()->with('scheduleType')->first();
    }
}
