<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;

class EmployeeTrainingHoursAdjustmentService
{
    public function __construct(
        private readonly PayrollCalculationService $payrollCalculationService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function preview(PayrollPeriod $period, Employee $employee): array
    {
        return collect($this->schedule())
            ->map(function (array $day) use ($period, $employee): array {
                $review = DailyTimeReview::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->whereDate('date', $day['date'])
                    ->first();

                return [
                    'date' => $day['date'],
                    'type' => $day['type'],
                    'before_payable' => $this->hours((int) ($review?->payable_seconds ?? 0)),
                    'after_payable' => $this->hours($this->targetPayableSeconds($day)),
                    'after_regular' => $this->hours($this->targetRegularSeconds($day)),
                    'after_overtime' => $this->hours($this->targetOvertimeSeconds($day)),
                    'justified' => $this->hours((int) ($day['justified_seconds'] ?? 0)),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function apply(PayrollPeriod $period, Employee $employee): array
    {
        return DB::transaction(function () use ($period, $employee): array {
            foreach ($this->schedule() as $day) {
                $review = DailyTimeReview::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->whereDate('date', $day['date'])
                    ->first() ?? new DailyTimeReview([
                        'payroll_period_id' => $period->id,
                        'employee_id' => $employee->id,
                        'date' => $day['date'],
                    ]);

                $this->fillReview($review, $day);
                $review->save();
            }

            $employee->forceFill([
                'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
            ]);

            $this->payrollCalculationService->recalculateEmployeePayrollResult($period, $employee);

            return $this->preview($period, $employee);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function schedule(): array
    {
        return [
            ['date' => '2026-06-26', 'type' => 'Entrenamiento', 'regular_seconds' => 28800],
            ['date' => '2026-06-27', 'type' => 'OFF'],
            ['date' => '2026-06-28', 'type' => 'OFF'],
            ['date' => '2026-06-29', 'type' => 'Entrenamiento', 'regular_seconds' => 28800],
            ['date' => '2026-06-30', 'type' => 'Entrenamiento', 'regular_seconds' => 28800],
            ['date' => '2026-07-01', 'type' => 'Entrenamiento', 'regular_seconds' => 28800],
            ['date' => '2026-07-02', 'type' => 'Entrenamiento', 'regular_seconds' => 28800],
            ['date' => '2026-07-03', 'type' => 'OFF'],
            ['date' => '2026-07-04', 'type' => 'Rotativa parcial', 'regular_seconds' => 36000, 'scheduled_seconds' => 39600, 'overtime_seconds' => 3600],
            ['date' => '2026-07-05', 'type' => 'Rotativa justificada', 'regular_seconds' => 39600, 'overtime_seconds' => 3600, 'paid_not_tracked_seconds' => 3600, 'justified_seconds' => 6600],
            ['date' => '2026-07-06', 'type' => 'Rotativa', 'regular_seconds' => 39600, 'overtime_seconds' => 3600, 'paid_not_tracked_seconds' => 3600],
            ['date' => '2026-07-07', 'type' => 'Rotativa', 'regular_seconds' => 39600, 'overtime_seconds' => 3600, 'paid_not_tracked_seconds' => 3600],
            ['date' => '2026-07-08', 'type' => 'OFF'],
            ['date' => '2026-07-09', 'type' => 'OFF'],
            ['date' => '2026-07-10', 'type' => 'OFF'],
        ];
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function fillReview(DailyTimeReview $review, array $day): void
    {
        $regularSeconds = (int) ($day['regular_seconds'] ?? 0);
        $scheduledSeconds = (int) ($day['scheduled_seconds'] ?? $regularSeconds);
        $overtimeSeconds = (int) ($day['overtime_seconds'] ?? 0);
        $payableOvertimeSeconds = $this->targetOvertimeSeconds($day);
        $paidNotTrackedSeconds = (int) ($day['paid_not_tracked_seconds'] ?? 0);
        $justifiedSeconds = (int) ($day['justified_seconds'] ?? 0);
        $hubstaffSeconds = max($regularSeconds + $payableOvertimeSeconds - $paidNotTrackedSeconds - $justifiedSeconds, 0);
        $expectedPaidSeconds = $scheduledSeconds + $overtimeSeconds;
        $expectedHubstaffSeconds = max($expectedPaidSeconds - $paidNotTrackedSeconds, 0);
        $isOff = $regularSeconds <= 0 && $overtimeSeconds <= 0;

        $review->fill([
            'scheduled_work_day' => ! $isOff,
            'expected_seconds' => $scheduledSeconds,
            'expected_ordinary_seconds' => $scheduledSeconds,
            'assigned_overtime_seconds' => $overtimeSeconds,
            'preassigned_overtime_seconds' => $overtimeSeconds,
            'additional_overtime_seconds' => 0,
            'assigned_overtime_fulfilled' => false,
            'expected_paid_seconds' => $expectedPaidSeconds,
            'expected_hubstaff_seconds' => $expectedHubstaffSeconds,
            'hubstaff_total_seconds' => $hubstaffSeconds,
            'hubstaff_regular_seconds' => $hubstaffSeconds,
            'hubstaff_idle_seconds' => 0,
            'activity_percentage' => null,
            'idle_percentage' => null,
            'pto_seconds' => 0,
            'holiday_seconds' => 0,
            'paid_day_off' => $isOff,
            'paid_break_seconds' => 0,
            'paid_time_not_tracked_seconds' => $paidNotTrackedSeconds,
            'pending_idle_seconds' => 0,
            'justified_idle_seconds' => 0,
            'unjustified_idle_seconds' => 0,
            'justified_absence_seconds' => $justifiedSeconds,
            'unjustified_absence_seconds' => max($expectedPaidSeconds - $hubstaffSeconds - $paidNotTrackedSeconds - $justifiedSeconds, 0),
            'possible_overtime_seconds' => $payableOvertimeSeconds,
            'approved_overtime_seconds' => 0,
            'payable_seconds' => $this->targetPayableSeconds($day),
            'difference_seconds' => $hubstaffSeconds - $expectedHubstaffSeconds,
            'status' => 'revisado_supervisor',
            'supervisor_comment' => 'Ajuste puntual Edwin Cruz: entrenamiento/transición rotativa 26 junio - 10 julio 2026.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function targetPayableSeconds(array $day): int
    {
        return $this->targetRegularSeconds($day) + $this->targetOvertimeSeconds($day);
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function targetRegularSeconds(array $day): int
    {
        return (int) ($day['regular_seconds'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function targetOvertimeSeconds(array $day): int
    {
        $regularSeconds = (int) ($day['regular_seconds'] ?? 0);
        $overtimeSeconds = (int) ($day['overtime_seconds'] ?? 0);
        $paidNotTrackedSeconds = (int) ($day['paid_not_tracked_seconds'] ?? 0);
        $justifiedSeconds = (int) ($day['justified_seconds'] ?? 0);
        $creditedSeconds = $regularSeconds + $overtimeSeconds;

        return min(
            $overtimeSeconds,
            max($creditedSeconds - (int) ($day['scheduled_seconds'] ?? $regularSeconds), 0),
            max($creditedSeconds - $paidNotTrackedSeconds - $justifiedSeconds - $regularSeconds, 0) + $paidNotTrackedSeconds + $justifiedSeconds,
        );
    }

    private function hours(int $seconds): string
    {
        return number_format($seconds / 3600, 2);
    }
}
