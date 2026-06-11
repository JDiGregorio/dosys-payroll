<?php

namespace App\Services;

use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\EmployeeAdditionalDeduction;
use App\Models\PayrollDeduction;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;

class DeductionApplicationService
{
    public function generateForPeriod(PayrollPeriod $period): void
    {
        $deductionTypeIds = $period->deductionTypes()->pluck('deduction_types.id');

        PayrollDeduction::query()
            ->where('payroll_period_id', $period->id)
            ->delete();

        if ($period->apply_deductions) {
            Employee::query()
                ->where('active', true)
                ->get()
                ->each(function (Employee $employee) use ($period, $deductionTypeIds): void {
                    $this->ensureFlaggedDeductions($period, $employee, $deductionTypeIds);
                });
        }

        $this->ensureAdditionalDeductions($period);
    }

    public function approvedForEmployee(PayrollPeriod $period, Employee $employee): Collection
    {
        return PayrollDeduction::query()
            ->with('deductionType')
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->where('status', 'aprobado')
            ->get();
    }

    public function amountByCode(PayrollPeriod $period, Employee $employee, string $code): float
    {
        return (float) $this->approvedForEmployee($period, $employee)
            ->filter(fn (PayrollDeduction $deduction): bool => $deduction->deductionType?->code === $code)
            ->sum('amount');
    }

    private function ensureFlaggedDeductions(PayrollPeriod $period, Employee $employee, Collection $deductionTypeIds): void
    {
        $flags = [
            'private_insurance' => $employee->applies_private_insurance,
            'ihss' => $employee->applies_ihss,
            'isr' => $employee->applies_isr,
            'rap' => $employee->applies_rap,
        ];

        foreach ($flags as $code => $applies) {
            if (! $applies) {
                continue;
            }

            $type = DeductionType::query()->where('code', $code)->first();

            if (! $type || ! $deductionTypeIds->contains($type->id)) {
                continue;
            }

            PayrollDeduction::query()->updateOrCreate([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'deduction_type_id' => $type->id,
            ], [
                'amount' => $type->default_amount,
                'description' => 'Generada por configuración del empleado',
                'status' => 'aprobado',
            ]);
        }
    }

    private function ensureAdditionalDeductions(PayrollPeriod $period): void
    {
        $type = $this->additionalDeductionType();

        if (! $type) {
            return;
        }

        EmployeeAdditionalDeduction::query()
            ->where('payroll_period_id', $period->id)
            ->where('active', true)
            ->get()
            ->each(function (EmployeeAdditionalDeduction $deduction) use ($period, $type): void {
                PayrollDeduction::query()->updateOrCreate([
                    'payroll_period_id' => $period->id,
                    'employee_id' => $deduction->employee_id,
                    'deduction_type_id' => $type->id,
                    'description' => $deduction->description,
                ], [
                    'amount' => $deduction->amount,
                    'status' => 'aprobado',
                ]);
            });
    }

    private function additionalDeductionType(): ?DeductionType
    {
        return DeductionType::query()->where('code', 'additional')->first();
    }
}
