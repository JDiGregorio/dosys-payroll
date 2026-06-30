<?php

namespace App\Exports;

use App\Models\PayrollBonus;
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
    private const BONUS_LABELS = [
        'qa' => 'Bono QA',
        'productivity' => 'Bono de Productividad',
        'time_management' => 'Bono TM',
        'referred' => 'Bono referido',
        'adjustment' => 'Ajuste',
        'internet_subsidy' => 'Subsidio por internet',
    ];

    private const BONUS_FIELDS = [
        'qa' => 'qa_bonus_amount',
        'productivity' => 'productivity_bonus_amount',
        'time_management' => 'time_management_bonus_amount',
        'referred' => 'referred_bonus_amount',
        'adjustment' => 'adjustment_bonus_amount',
        'internet_subsidy' => 'internet_subsidy_amount',
    ];

    private ?Collection $bonusTypes = null;

    private ?Collection $deductionTypes = null;

    private ?Collection $deductionsByEmployee = null;

    public function __construct(private PayrollPeriod $period) {}

    public function query()
    {
        return PayrollResult::query()
            ->with([
                'employee.campaign',
                'employee.team',
                'employee.workRole',
                'employee.tierLevel',
                'employee.scheduleType',
            ])
            ->where('payroll_period_id', $this->period->id)
            ->orderBy('employee_id');
    }

    public function headings(): array
    {
        $headings = [
            'Referencia - ID',
            'No. Cuenta',
            'Nombre empleado',
            'Campaña',
            'Equipo',
            'Posición',
            'Salario mensual',
            'Salario quincenal',
            'Tier level',
            'Pago por día',
            'Hora',
            'Hora extra',
            'Días trabajados',
            'Salario',
            'Bonos extras',
            'Horas extras',
        ];

        foreach ($this->bonusTypes() as $type) {
            $headings[] = self::BONUS_LABELS[$type] ?? str($type)->headline()->toString();
        }

        $headings[] = 'Total devengado';

        foreach ($this->deductionTypes() as $deductionType) {
            $headings[] = $deductionType->name;
        }

        return array_merge($headings, [
            'Total deducciones',
            'Total a pagar',
        ]);
    }

    public function map($row): array
    {
        $employee = $row->employee;

        $values = [
            $employee?->dni,
            $employee?->bank_account_number,
            $employee?->name,
            $employee?->campaign?->name,
            $employee?->team?->name,
            $employee?->workRole?->name,
            $row->monthly_salary,
            $row->biweekly_salary_amount,
            $employee?->tierLevel?->name,
            $row->daily_rate,
            $row->hourly_rate,
            $row->overtime_hourly_rate,
            $row->displayWorkedDays(),
            $row->worked_salary_amount,
            $row->extra_bonuses_amount,
            $row->overtime_amount,
        ];

        foreach ($this->bonusTypes() as $type) {
            $values[] = $row->{self::BONUS_FIELDS[$type]} ?? 0;
        }

        $values[] = $row->gross_amount;
        $employeeDeductions = $this->deductionsByEmployee()->get($row->employee_id, collect());

        foreach ($this->deductionTypes() as $deductionType) {
            $values[] = $employeeDeductions
                ->where('deduction_type_id', $deductionType->id)
                ->sum('amount');
        }

        return array_merge($values, [
            $row->total_deductions_amount,
            $row->net_amount,
        ]);
    }

    private function bonusTypes(): Collection
    {
        if ($this->bonusTypes !== null) {
            return $this->bonusTypes;
        }

        $typesInPeriod = PayrollBonus::query()
            ->where('payroll_period_id', $this->period->id)
            ->where('status', '!=', 'rechazado')
            ->where('amount', '>', 0)
            ->whereNotIn('type', ['manual', 'other'])
            ->pluck('type')
            ->unique();

        if (PayrollResult::query()
            ->where('payroll_period_id', $this->period->id)
            ->where('internet_subsidy_amount', '>', 0)
            ->exists()) {
            $typesInPeriod->push('internet_subsidy');
        }

        return $this->bonusTypes = collect(array_keys(self::BONUS_LABELS))
            ->filter(fn (string $type): bool => $typesInPeriod->contains($type))
            ->values();
    }

    private function deductionTypes(): Collection
    {
        if ($this->deductionTypes !== null) {
            return $this->deductionTypes;
        }

        return $this->deductionTypes = PayrollDeduction::query()
            ->with('deductionType')
            ->where('payroll_period_id', $this->period->id)
            ->where('status', 'aprobado')
            ->where('amount', '>', 0)
            ->get()
            ->pluck('deductionType')
            ->filter()
            ->unique('id')
            ->values();
    }

    private function deductionsByEmployee(): Collection
    {
        if ($this->deductionsByEmployee !== null) {
            return $this->deductionsByEmployee;
        }

        return $this->deductionsByEmployee = PayrollDeduction::query()
            ->where('payroll_period_id', $this->period->id)
            ->where('status', 'aprobado')
            ->where('amount', '>', 0)
            ->get()
            ->groupBy('employee_id');
    }
}
