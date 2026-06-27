<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\ScheduleType;
use App\Models\WorkScheduleTemplate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RotatingScheduleCorrectionService
{
    private const EMPLOYEES = [
        ['prefix' => 'Elalf Shamir', 'anchor_date' => '2026-05-25'],
        ['prefix' => 'Emely Charl', 'anchor_date' => '2026-05-29'],
        ['prefix' => 'Wilman', 'anchor_date' => '2026-05-25'],
        ['prefix' => 'Valery Rachel', 'anchor_date' => '2026-05-29'],
    ];

    public function __construct(
        private readonly PayrollCalculationService $payrollCalculationService,
    ) {}

    /**
     * @return array<int, array<string, int|string>>
     */
    public function preview(PayrollPeriod $period): array
    {
        return collect(self::EMPLOYEES)
            ->map(function (array $definition) use ($period): array {
                $employee = $this->resolveEmployee($definition['prefix']);
                $reviews = DailyTimeReview::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id);

                return [
                    'employee_id' => $employee->id,
                    'name' => $employee->name,
                    'anchor_date' => $definition['anchor_date'],
                    'reviews' => (clone $reviews)->count(),
                    'reviewed' => (clone $reviews)
                        ->where(fn ($query) => $query
                            ->where('status', '!=', 'pendiente')
                            ->orWhere('justified_absence_seconds', '>', 0)
                            ->orWhere('paid_day_off', true)
                            ->orWhereNotNull('supervisor_comment')
                            ->orWhereNotNull('rrhh_comment'))
                        ->count(),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function apply(PayrollPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $rotatingScheduleId = ScheduleType::query()
                ->where('code', 'rotativa')
                ->value('id');

            if (! $rotatingScheduleId) {
                throw new RuntimeException('No existe una jornada Rotativa configurada.');
            }

            $rotatingTemplateId = WorkScheduleTemplate::query()
                ->where('schedule_type', 'rotativa')
                ->where('active', true)
                ->value('id');

            $previewByEmployee = collect($this->preview($period))->keyBy('employee_id');
            $targetEmployees = collect(self::EMPLOYEES)
                ->map(fn (array $definition): Employee => $this->resolveEmployee($definition['prefix']));
            $targetEmployeeIds = $targetEmployees->pluck('id');
            $protectedReviewState = $this->protectedReviewState($period, $targetEmployeeIds->all());
            $results = [];

            foreach (self::EMPLOYEES as $index => $definition) {
                $employee = $targetEmployees->get($index);
                $preview = $previewByEmployee->get($employee->id);

                $employee->update([
                    'schedule_type_id' => $rotatingScheduleId,
                    'work_schedule_template_id' => $rotatingTemplateId,
                    'schedule_cycle_anchor_date' => $definition['anchor_date'],
                    'rotation_work_days' => 4,
                    'rotation_rest_days' => 4,
                    'weekly_hours' => 44,
                    'ordinary_weekly_hours' => 44,
                    'daily_hours' => 11,
                    'overtime_hours' => 4,
                    'preassigned_overtime_weekly_hours' => 4,
                    'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
                    'hubstaff_expected_hours_per_workday' => 11,
                    'paid_hours_per_workday' => 12,
                    'paid_lunch_minutes_per_workday' => 60,
                    'lunch_included_in_hubstaff_total' => false,
                    'breaks_included_in_hubstaff_total' => true,
                    'can_work_overtime' => true,
                ]);

                $results[] = $preview;
            }

            $this->payrollCalculationService->recalculatePeriodPreservingManual($period);
            $this->assertProtectedReviewStateUnchanged($period, $targetEmployeeIds->all(), $protectedReviewState);
            $period->update(['status' => 'en_revision']);

            return $results;
        });
    }

    private function resolveEmployee(string $prefix): Employee
    {
        $employees = Employee::query()
            ->where('name', 'like', $prefix.'%')
            ->get();

        if ($employees->count() !== 1) {
            throw new RuntimeException(
                "Se esperaba encontrar exactamente un empleado cuyo nombre inicia con \"{$prefix}\"; se encontraron {$employees->count()}.",
            );
        }

        return $employees->first();
    }

    /**
     * @param  array<int, int>  $excludedEmployeeIds
     * @return array<int, array<string, mixed>>
     */
    private function protectedReviewState(PayrollPeriod $period, array $excludedEmployeeIds): array
    {
        return DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->whereNotIn('employee_id', $excludedEmployeeIds)
            ->get()
            ->mapWithKeys(fn (DailyTimeReview $review): array => [
                $review->id => $this->normalizedProtectedReviewState($review),
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $excludedEmployeeIds
     * @param  array<int, array<string, mixed>>  $expectedState
     */
    private function assertProtectedReviewStateUnchanged(
        PayrollPeriod $period,
        array $excludedEmployeeIds,
        array $expectedState,
    ): void {
        $actualState = $this->protectedReviewState($period, $excludedEmployeeIds);

        if ($actualState !== $expectedState) {
            throw new RuntimeException(
                'La corrección intentó modificar justificaciones o revisiones de otros empleados. No se aplicó ningún cambio.',
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function protectedReviewFields(): array
    {
        return [
            'status',
            'justified_absence_seconds',
            'paid_day_off',
            'supervisor_comment',
            'rrhh_comment',
            'reviewed_by',
            'approved_by',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedProtectedReviewState(DailyTimeReview $review): array
    {
        $state = $review->only($this->protectedReviewFields());

        if ($review->paid_day_off) {
            $state['justified_absence_seconds'] = 0;
        }

        return $state;
    }
}
