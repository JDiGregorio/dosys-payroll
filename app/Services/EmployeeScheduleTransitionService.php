<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\ScheduleType;
use App\Models\WorkScheduleTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EmployeeScheduleTransitionService
{
    public function __construct(
        private readonly PayrollCalculationService $payrollCalculationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        PayrollPeriod $period,
        string $employeeName,
        Carbon $rotativeStart,
        Carbon $rotativeEnd,
        Carbon $diurnalStart,
    ): array {
        $employee = $this->employeeByName($employeeName);

        return [
            'period_id' => $period->id,
            'period' => $period->name,
            'employee_id' => $employee->id,
            'employee' => $employee->name,
            'rotative_start' => $rotativeStart->toDateString(),
            'rotative_end' => $rotativeEnd->toDateString(),
            'diurnal_start' => $diurnalStart->toDateString(),
            'current_schedule' => $employee->scheduleType?->name,
            'current_template' => $employee->workScheduleTemplate?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(
        PayrollPeriod $period,
        string $employeeName,
        Carbon $rotativeStart,
        Carbon $rotativeEnd,
        Carbon $diurnalStart,
    ): array {
        if ($period->status === 'cerrado') {
            throw new RuntimeException('El período está cerrado y no será modificado.');
        }

        return DB::transaction(function () use ($period, $employeeName, $rotativeStart, $rotativeEnd, $diurnalStart): array {
            $employee = $this->employeeByName($employeeName);
            $preview = $this->preview($period, $employeeName, $rotativeStart, $rotativeEnd, $diurnalStart);
            $diurnalSchedule = $this->scheduleType('diurna');
            $rotativeTemplate = $this->template('Rotativa 4x4');
            $diurnalTemplate = $this->template('Diurna 40h - 5 días x 8h');

            $employee->scheduleAssignments()->updateOrCreate([
                'starts_at' => $rotativeStart->toDateString(),
                'ends_at' => $rotativeEnd->toDateString(),
            ], [
                'work_schedule_template_id' => $rotativeTemplate->id,
                'cycle_start_date' => $employee->schedule_cycle_anchor_date?->toDateString() ?: $rotativeStart->toDateString(),
                'rotation_work_days' => 4,
                'rotation_rest_days' => 4,
                'active' => true,
            ]);

            $employee->scheduleAssignments()->updateOrCreate([
                'starts_at' => $diurnalStart->toDateString(),
                'ends_at' => null,
            ], [
                'work_schedule_template_id' => $diurnalTemplate->id,
                'cycle_start_date' => null,
                'rotation_work_days' => null,
                'rotation_rest_days' => null,
                'active' => true,
            ]);

            $employee->update([
                'schedule_type_id' => $diurnalSchedule->id,
                'work_schedule_template_id' => $diurnalTemplate->id,
                'schedule_cycle_anchor_date' => null,
                'rotation_work_days' => null,
                'rotation_rest_days' => null,
                'weekly_hours' => 40,
                'ordinary_weekly_hours' => 40,
                'daily_hours' => 8,
                'overtime_hours' => 5,
                'preassigned_overtime_weekly_hours' => 5,
                'preassigned_overtime_period_hours' => 0,
                'hubstaff_expected_hours_per_workday' => null,
                'paid_hours_per_workday' => null,
                'paid_lunch_minutes_per_workday' => 0,
                'lunch_included_in_hubstaff_total' => true,
                'breaks_included_in_hubstaff_total' => true,
                'can_work_overtime' => true,
            ]);

            $this->payrollCalculationService->recalculatePeriodPreservingManual($period);

            return $preview;
        });
    }

    private function employeeByName(string $name): Employee
    {
        $employees = Employee::query()
            ->with(['scheduleType', 'workScheduleTemplate'])
            ->where('name', $name)
            ->get();

        if ($employees->isEmpty()) {
            $employees = Employee::query()
                ->with(['scheduleType', 'workScheduleTemplate'])
                ->where('name', 'like', $name.'%')
                ->get();
        }

        if ($employees->count() !== 1) {
            throw new RuntimeException("Se esperaba encontrar exactamente un empleado para \"{$name}\"; se encontraron {$employees->count()}.");
        }

        return $employees->first();
    }

    private function scheduleType(string $code): ScheduleType
    {
        return ScheduleType::query()
            ->where('code', $code)
            ->firstOrFail();
    }

    private function template(string $name): WorkScheduleTemplate
    {
        return WorkScheduleTemplate::query()
            ->where('name', $name)
            ->firstOrFail();
    }
}
