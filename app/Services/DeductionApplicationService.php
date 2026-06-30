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

            PayrollDeduction::query()->firstOrCreate([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'deduction_type_id' => $type->id,
                'employee_additional_deduction_id' => null,
            ], [
                'additional_type' => null,
                'amount' => $type->default_amount,
                'description' => 'Generada por configuración del empleado',
                'status' => 'aprobado',
            ]);
        }
    }

    private function ensureAdditionalDeductions(PayrollPeriod $period): void
    {
        $activeAdditionalDeductionIds = EmployeeAdditionalDeduction::query()
            ->where('payroll_period_id', $period->id)
            ->where('active', true)
            ->pluck('id');

        PayrollDeduction::query()
            ->where('payroll_period_id', $period->id)
            ->whereNotNull('employee_additional_deduction_id')
            ->whereNotIn('employee_additional_deduction_id', $activeAdditionalDeductionIds)
            ->delete();

        EmployeeAdditionalDeduction::query()
            ->with('employee')
            ->where('payroll_period_id', $period->id)
            ->where('active', true)
            ->whereHas('employee', fn ($query) => $query->where('active', true))
            ->get()
            ->each(function (EmployeeAdditionalDeduction $deduction) use ($period): void {
                $type = $this->deductionTypeForAdditional($deduction);

                if (! $type) {
                    return;
                }

                $this->linkLegacyAdditionalDeduction($deduction, $type);

                PayrollDeduction::query()->updateOrCreate([
                    'employee_additional_deduction_id' => $deduction->id,
                ], [
                    'payroll_period_id' => $period->id,
                    'employee_id' => $deduction->employee_id,
                    'deduction_type_id' => $type->id,
                    'additional_type' => $deduction->type ?: 'other',
                    'description' => $deduction->description,
                    'amount' => $deduction->amount,
                    'status' => 'aprobado',
                ]);
            });
    }

    private function linkLegacyAdditionalDeduction(EmployeeAdditionalDeduction $deduction, DeductionType $type): void
    {
        PayrollDeduction::query()
            ->whereNull('employee_additional_deduction_id')
            ->where('payroll_period_id', $deduction->payroll_period_id)
            ->where('employee_id', $deduction->employee_id)
            ->where('description', $deduction->description)
            ->where('amount', $deduction->amount)
            ->whereHas('deductionType', fn ($query) => $query->where('code', 'additional'))
            ->orderBy('id')
            ->limit(1)
            ->update([
                'employee_additional_deduction_id' => $deduction->id,
                'deduction_type_id' => $type->id,
                'additional_type' => $deduction->type ?: 'other',
            ]);
    }

    private function deductionTypeForAdditional(EmployeeAdditionalDeduction $deduction): ?DeductionType
    {
        return DeductionType::query()
            ->where('code', match ($deduction->type) {
                'ihss' => 'ihss',
                'private_insurance' => 'private_insurance',
                default => 'additional',
            })
            ->first();
    }
}
