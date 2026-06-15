<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\ScheduleType;
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
                    'schedule_cycle_anchor_date' => $definition['anchor_date'],
                    'weekly_hours' => 40,
                    'daily_hours' => 10,
                    'overtime_hours' => 4,
                    'can_work_overtime' => true,
                ]);
                $employee->refresh();

                $this->payrollCalculationService->regenerateEmployeeDailyReviews(
                    $period,
                    $employee,
                    resetReviewState: true,
                );

                $results[] = $preview;
            }

            $this->payrollCalculationService->recalculatePayrollResults($period);
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
                $review->id => $review->only($this->protectedReviewFields()),
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
}
