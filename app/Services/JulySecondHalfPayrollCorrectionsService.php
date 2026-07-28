<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;

class JulySecondHalfPayrollCorrectionsService
{
    private const OVERTIME_EMPLOYEE_IDS = [61, 62];

    private const EDWIN_CRUZ_ID = 63;

    private const OVERTIME_DESCRIPTION = 'Ajuste puntual horas extra desde 20 julio 2026.';

    private const DAILY_OVERTIME_COMMENT = 'Ajuste puntual: 1h extra diaria asignada desde 20 julio 2026.';

    public function __construct(
        private readonly PayrollCalculationService $payrollCalculationService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function preview(PayrollPeriod $period): array
    {
        $rows = [];

        foreach (self::OVERTIME_EMPLOYEE_IDS as $employeeId) {
            $employee = Employee::query()->find($employeeId);

            if (! $employee) {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'employee' => 'No encontrado',
                    'action' => 'Hora extra adicional',
                    'before' => 'N/A',
                    'after' => 'N/A',
                ];

                continue;
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->name,
                'action' => 'Hora extra diaria desde 20 julio',
                'before' => $this->dailyOvertimeSummary($period, $employee),
                'after' => '5 días x 1.00h extra diaria en revisión',
            ];
        }

        $edwin = Employee::query()->find(self::EDWIN_CRUZ_ID);

        foreach ($this->edwinCorrectionDates() as $date) {
            $review = $edwin
                ? DailyTimeReview::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $edwin->id)
                    ->whereDate('date', $date)
                    ->first()
                : null;
            $target = $this->edwinReviewTarget($date, $review);

            $rows[] = [
                'employee_id' => self::EDWIN_CRUZ_ID,
                'employee' => $edwin?->name ?? 'No encontrado',
                'action' => "Edwin {$date}",
                'before' => $this->reviewSummary($review),
                'after' => $target['summary'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function apply(PayrollPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $this->ensureJulySecondHalfPeriod($period);
            $affectedEmployeeIds = [];

            foreach (self::OVERTIME_EMPLOYEE_IDS as $employeeId) {
                $employee = Employee::query()->findOrFail($employeeId);
                $this->removeLegacyOvertimeAdjustment($period, $employee);
                $this->applyDailyOvertimeFromJulyTwentieth($period, $employee);
                $affectedEmployeeIds[] = $employee->id;
            }

            $edwin = Employee::query()->findOrFail(self::EDWIN_CRUZ_ID);
            $this->applyEdwinCorrections($period, $edwin);
            $affectedEmployeeIds[] = $edwin->id;

            collect($affectedEmployeeIds)
                ->unique()
                ->each(function (int $employeeId) use ($period): void {
                    $employee = Employee::query()->findOrFail($employeeId);
                    $this->payrollCalculationService->recalculateEmployeePayrollResult($period, $employee);
                });

            return $this->preview($period);
        });
    }

    private function ensureJulySecondHalfPeriod(PayrollPeriod $period): void
    {
        if (
            ! $period->starts_at?->isSameDay('2026-07-11')
            || ! $period->ends_at?->isSameDay('2026-07-25')
        ) {
            throw new \RuntimeException('Este ajuste está limitado al período 11 julio 2026 - 25 julio 2026.');
        }

        if ($period->status === 'cerrado') {
            throw new \RuntimeException('El período está cerrado y no será modificado.');
        }
    }

    private function removeLegacyOvertimeAdjustment(PayrollPeriod $period, Employee $employee): void
    {
        $employee->payrollOvertimeAdjustments()
            ->where('payroll_period_id', $period->id)
            ->where('description', self::OVERTIME_DESCRIPTION)
            ->delete();
    }

    private function applyDailyOvertimeFromJulyTwentieth(PayrollPeriod $period, Employee $employee): void
    {
        $reviews = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', '2026-07-20')
            ->whereDate('date', '<=', '2026-07-25')
            ->where('scheduled_work_day', true)
            ->where('paid_day_off', false)
            ->where('hubstaff_total_seconds', '>', 0)
            ->orderBy('date')
            ->limit(5)
            ->get();

        foreach ($reviews as $review) {
            $ordinarySeconds = max((int) $review->expected_ordinary_seconds, 0);
            $overtimeSeconds = 3600;
            $expectedPaidSeconds = $ordinarySeconds + $overtimeSeconds;
            $paidNotTrackedSeconds = max((int) $review->paid_time_not_tracked_seconds, 0);
            $creditedSeconds = min(
                max((int) $review->hubstaff_total_seconds + $paidNotTrackedSeconds, 0),
                $expectedPaidSeconds,
            );
            $paidOvertimeSeconds = min(
                $overtimeSeconds,
                max($creditedSeconds - $ordinarySeconds, 0),
            );
            $overtimeFulfilled = $paidOvertimeSeconds >= $overtimeSeconds;

            $review->fill([
                'assigned_overtime_seconds' => $overtimeSeconds,
                'preassigned_overtime_seconds' => $overtimeSeconds,
                'additional_overtime_seconds' => 0,
                'assigned_overtime_fulfilled' => $overtimeFulfilled,
                'expected_paid_seconds' => $expectedPaidSeconds,
                'expected_hubstaff_seconds' => $expectedPaidSeconds,
                'justified_absence_seconds' => 0,
                'unjustified_absence_seconds' => 0,
                'possible_overtime_seconds' => $paidOvertimeSeconds,
                'payable_seconds' => $creditedSeconds,
                'difference_seconds' => (int) $review->hubstaff_total_seconds - $expectedPaidSeconds,
                'status' => 'pendiente',
                'supervisor_comment' => $this->removeDailyOvertimeComment($review->supervisor_comment),
            ]);
            $review->save();
        }
    }

    private function applyEdwinCorrections(PayrollPeriod $period, Employee $employee): void
    {
        foreach ($this->edwinCorrectionDates() as $date) {
            $review = DailyTimeReview::query()
                ->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->first() ?? new DailyTimeReview([
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'date' => $date,
                ]);
            $target = $this->edwinReviewTarget($date, $review);

            $review->fill($target['attributes']);
            $review->save();
        }

        HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', '2026-07-16')
            ->update(['active' => false]);
    }

    /**
     * @return array<string, array{summary: string, attributes: array<string, mixed>}>
     */
    private function edwinCorrectionDates(): array
    {
        return [
            '2026-07-12',
            '2026-07-13',
            '2026-07-14',
            '2026-07-16',
        ];
    }

    /**
     * @return array{summary: string, attributes: array<string, mixed>}
     */
    private function edwinReviewTarget(string $date, ?DailyTimeReview $review): array
    {
        return match ($date) {
            '2026-07-12', '2026-07-13' => $this->edwinWorkedTwelveHoursTarget('Ajuste puntual Edwin Cruz: trabajó 12h completas por problema de Hubstaff.'),
            '2026-07-14' => $this->edwinJulyFourteenthTarget($review),
            '2026-07-16' => [
                'summary' => 'OFF, Hubstaff eliminado del cálculo',
                'attributes' => [
                    'scheduled_work_day' => false,
                    'expected_seconds' => 0,
                    'expected_ordinary_seconds' => 0,
                    'assigned_overtime_seconds' => 0,
                    'preassigned_overtime_seconds' => 0,
                    'additional_overtime_seconds' => 0,
                    'assigned_overtime_fulfilled' => false,
                    'expected_paid_seconds' => 0,
                    'expected_hubstaff_seconds' => 0,
                    'hubstaff_total_seconds' => 0,
                    'hubstaff_regular_seconds' => 0,
                    'hubstaff_idle_seconds' => 0,
                    'activity_percentage' => null,
                    'idle_percentage' => null,
                    'pto_seconds' => 0,
                    'holiday_seconds' => 0,
                    'paid_day_off' => true,
                    'paid_break_seconds' => 0,
                    'paid_time_not_tracked_seconds' => 0,
                    'pending_idle_seconds' => 0,
                    'justified_idle_seconds' => 0,
                    'unjustified_idle_seconds' => 0,
                    'justified_absence_seconds' => 0,
                    'unjustified_absence_seconds' => 0,
                    'possible_overtime_seconds' => 0,
                    'approved_overtime_seconds' => 0,
                    'payable_seconds' => 0,
                    'difference_seconds' => 0,
                    'status' => 'revisado_supervisor',
                    'supervisor_comment' => 'Ajuste puntual Edwin Cruz: 16 julio OFF, tiempo Hubstaff generado por uso equivocado de PC.',
                ],
            ],
            default => throw new \InvalidArgumentException("Fecha no soportada para ajuste de Edwin: {$date}."),
        };
    }

    /**
     * @return array{summary: string, attributes: array<string, mixed>}
     */
    private function edwinWorkedTwelveHoursTarget(string $comment): array
    {
        return [
            'summary' => '12.00h pagables, 11.00h Hubstaff esperadas + 1.00h pagada no trackeada',
            'attributes' => [
                'scheduled_work_day' => true,
                'expected_seconds' => 39600,
                'expected_ordinary_seconds' => 39600,
                'assigned_overtime_seconds' => 3600,
                'preassigned_overtime_seconds' => 3600,
                'additional_overtime_seconds' => 0,
                'assigned_overtime_fulfilled' => true,
                'expected_paid_seconds' => 43200,
                'expected_hubstaff_seconds' => 39600,
                'hubstaff_total_seconds' => 39600,
                'hubstaff_regular_seconds' => 39600,
                'hubstaff_idle_seconds' => 0,
                'activity_percentage' => null,
                'idle_percentage' => null,
                'pto_seconds' => 0,
                'holiday_seconds' => 0,
                'paid_day_off' => false,
                'paid_break_seconds' => 0,
                'paid_time_not_tracked_seconds' => 3600,
                'pending_idle_seconds' => 0,
                'justified_idle_seconds' => 0,
                'unjustified_idle_seconds' => 0,
                'justified_absence_seconds' => 0,
                'unjustified_absence_seconds' => 0,
                'possible_overtime_seconds' => 3600,
                'approved_overtime_seconds' => 0,
                'payable_seconds' => 43200,
                'difference_seconds' => 0,
                'status' => 'revisado_supervisor',
                'supervisor_comment' => $comment,
            ],
        ];
    }

    /**
     * @return array{summary: string, attributes: array<string, mixed>}
     */
    private function edwinJulyFourteenthTarget(?DailyTimeReview $review): array
    {
        $hubstaffSeconds = max((int) ($review?->hubstaff_total_seconds ?? 0), 0);

        if ($hubstaffSeconds <= 0) {
            return $this->edwinWorkedTwelveHoursTarget('Ajuste puntual Edwin Cruz: trabajó 12h completas por problema de Hubstaff.');
        }

        $paidNotTrackedSeconds = 3600;
        $justifiedSeconds = max(43200 - $hubstaffSeconds - $paidNotTrackedSeconds, 0);

        return [
            'summary' => sprintf(
                '12.00h pagables, %.2fh Hubstaff + 1.00h pagada no trackeada + %.2fh justificadas',
                $hubstaffSeconds / 3600,
                $justifiedSeconds / 3600,
            ),
            'attributes' => [
                'scheduled_work_day' => true,
                'expected_seconds' => 39600,
                'expected_ordinary_seconds' => 39600,
                'assigned_overtime_seconds' => 3600,
                'preassigned_overtime_seconds' => 3600,
                'additional_overtime_seconds' => 0,
                'assigned_overtime_fulfilled' => true,
                'expected_paid_seconds' => 43200,
                'expected_hubstaff_seconds' => 39600,
                'hubstaff_total_seconds' => $hubstaffSeconds,
                'hubstaff_regular_seconds' => $hubstaffSeconds,
                'hubstaff_idle_seconds' => (int) ($review?->hubstaff_idle_seconds ?? 0),
                'pto_seconds' => 0,
                'holiday_seconds' => 0,
                'paid_day_off' => false,
                'paid_break_seconds' => 0,
                'paid_time_not_tracked_seconds' => $paidNotTrackedSeconds,
                'pending_idle_seconds' => 0,
                'justified_idle_seconds' => 0,
                'unjustified_idle_seconds' => 0,
                'justified_absence_seconds' => $justifiedSeconds,
                'unjustified_absence_seconds' => 0,
                'possible_overtime_seconds' => 3600,
                'approved_overtime_seconds' => 0,
                'payable_seconds' => 43200,
                'difference_seconds' => $hubstaffSeconds - 39600,
                'status' => 'revisado_supervisor',
                'supervisor_comment' => 'Ajuste puntual Edwin Cruz: faltante de Hubstaff justificado para completar 12h.',
            ],
        ];
    }

    private function dailyOvertimeSummary(PayrollPeriod $period, Employee $employee): string
    {
        $reviews = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', '2026-07-20')
            ->whereDate('date', '<=', '2026-07-25')
            ->where('preassigned_overtime_seconds', '>', 0)
            ->orderBy('date')
            ->get(['date', 'preassigned_overtime_seconds']);

        if ($reviews->isEmpty()) {
            return 'Sin horas extra diarias';
        }

        return $reviews
            ->map(fn (DailyTimeReview $review): string => $review->date->toDateString().' '.$this->hours((int) $review->preassigned_overtime_seconds).'h')
            ->implode(', ');
    }

    private function reviewSummary(?DailyTimeReview $review): string
    {
        if (! $review) {
            return 'Sin revisión';
        }

        return sprintf(
            'Hubstaff %.2fh, pagable %.2fh, OFF %s, justificado %.2fh',
            ((int) $review->hubstaff_total_seconds) / 3600,
            ((int) $review->payable_seconds) / 3600,
            $review->paid_day_off ? 'sí' : 'no',
            ((int) $review->justified_absence_seconds) / 3600,
        );
    }

    private function hours(int $seconds): string
    {
        return number_format($seconds / 3600, 2);
    }

    private function removeDailyOvertimeComment(?string $comment): ?string
    {
        $cleaned = collect(preg_split('/\R/', (string) $comment))
            ->reject(fn (string $line): bool => trim($line) === self::DAILY_OVERTIME_COMMENT)
            ->implode("\n");

        return filled(trim($cleaned)) ? trim($cleaned) : null;
    }
}
