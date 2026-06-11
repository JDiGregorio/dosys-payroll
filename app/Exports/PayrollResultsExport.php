<?php

namespace App\Exports;

use App\Models\PayrollDeduction;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PayrollResultsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private PayrollPeriod $period) {}

    private ?Collection $extraDeductionTypes = null;

    public function query()
    {
        return PayrollResult::query()
            ->with(['employee.campaign', 'employee.tierLevel'])
            ->where('payroll_period_id', $this->period->id)
            ->orderBy('employee_id');
    }

    public function headings(): array
    {
        $headings = [
            'Nombre empleado',
            'Campaña',
            'Salario mensual',
            'Tier',
            'Pago por día',
            'Días trabajados',
            'Salario',
            'Bonos extras',
            'Horas extras',
            'Bono referido',
        ];

        if ($this->includeAdjustmentColumn()) {
            $headings[] = 'Ajuste';
        }

        $headings = array_merge($headings, [
            'Ingresos extra totales',
            'Total devengado',
            'IHSS',
        ]);

        foreach ($this->extraDeductionTypes() as $deductionType) {
            $headings[] = $deductionType->name;
        }

        return array_merge($headings, [
            'Total deducciones',
            'Total a pagar',
        ]);
    }

    public function map($row): array
    {
        $values = [
            $row->employee?->name,
            $row->employee?->campaign?->name,
            $row->monthly_salary,
            $row->employee?->tierLevel?->name,
            $row->daily_rate,
            $row->worked_days,
            $row->worked_salary_amount,
            $row->extra_bonuses_amount,
            $row->overtime_amount,
            $row->referred_bonus_amount,
        ];

        if ($this->includeAdjustmentColumn()) {
            $values[] = $row->adjustment_bonus_amount;
        }

        $values = array_merge($values, [
            $row->extras_total_amount,
            $row->gross_amount,
            $row->ihss_amount,
        ]);

        foreach ($this->extraDeductionTypes() as $deductionType) {
            $values[] = PayrollDeduction::query()
                ->where('payroll_period_id', $this->period->id)
                ->where('employee_id', $row->employee_id)
                ->where('deduction_type_id', $deductionType->id)
                ->sum('amount');
        }

        return array_merge($values, [
            $row->total_deductions_amount,
            $row->net_amount,
        ]);
    }

    private function includeAdjustmentColumn(): bool
    {
        return PayrollResult::query()
            ->where('payroll_period_id', $this->period->id)
            ->where('adjustment_bonus_amount', '>', 0)
            ->exists();
    }

    private function extraDeductionTypes(): Collection
    {
        if ($this->extraDeductionTypes !== null) {
            return $this->extraDeductionTypes;
        }

        $this->extraDeductionTypes = PayrollDeduction::query()
            ->with('deductionType')
            ->where('payroll_period_id', $this->period->id)
            ->where('amount', '>', 0)
            ->get()
            ->pluck('deductionType')
            ->filter(fn ($type) => $type && $type->code !== 'ihss')
            ->unique('id')
            ->values();

        return $this->extraDeductionTypes;
    }
}
