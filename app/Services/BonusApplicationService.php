<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollBonus;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;

class BonusApplicationService
{
    public function approvedBonusesForEmployee(PayrollPeriod $period, Employee $employee): Collection
    {
        return PayrollBonus::query()
            ->where('payroll_period_id', $period->id)
            ->where('status', '!=', 'rechazado')
            ->where(function ($query) use ($employee): void {
                $query
                    ->where(function ($query) use ($employee): void {
                        $query->where('scope_type', 'employee')
                            ->where('employee_id', $employee->id);
                    })
                    ->orWhere(function ($query) use ($employee): void {
                        $query->where('scope_type', 'team')
                            ->where('team_id', $employee->team_id);
                    })
                    ->orWhere(function ($query) use ($employee): void {
                        $query->where('scope_type', 'campaign')
                            ->where('campaign_id', $employee->campaign_id);
                    });
            })
            ->get();
    }

    public function amountByType(PayrollPeriod $period, Employee $employee, array $types): float
    {
        return (float) $this->approvedBonusesForEmployee($period, $employee)
            ->whereIn('type', $types)
            ->sum('amount');
    }
}
