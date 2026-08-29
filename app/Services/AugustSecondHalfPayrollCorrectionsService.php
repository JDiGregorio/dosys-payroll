<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;

class AugustSecondHalfPayrollCorrectionsService
{
    private const TEN_HOUR_EMPLOYEE_IDS = [45, 22];

    private const TEN_HOUR_DATES = [
        '2026-08-20',
        '2026-08-24',
        '2026-08-25',
    ];

    private const BRADARICK_ID = 35;

    private const DELMARK_ID = 43;

    private const TEN_HOUR_COMMENT = 'Ajuste puntual agosto 2026: 10h pagables indicadas por RRHH.';

    private const BRADARICK_OFF_COMMENT = 'Ajuste puntual agosto 2026: 24 agosto marcado como día libre.';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function preview(PayrollPeriod $period): array
    {
        $rows = [];

        foreach (self::TEN_HOUR_EMPLOYEE_IDS as $employeeId) {
            $employee = Employee::query()->find($employeeId);

            foreach (self::TEN_HOUR_DATES as $date) {
                $review = $this->review($period, $employee, $date);

                $rows[] = [
                    'employee_id' => $employeeId,
                    'employee' => $employee?->name ?? 'No encontrado',
                    'action' => "10h pagables {$date}",
                    'before' => $this->reviewSummary($review),
                    'after' => '10.00h pagables, sin descuento por idle',
                ];
            }
        }

        $bradarick = Employee::query()->find(self::BRADARICK_ID);
        $bradarickReview = $this->review($period, $bradarick, '2026-08-24');
        $rows[] = [
            'employee_id' => self::BRADARICK_ID,
            'employee' => $bradarick?->name ?? 'No encontrado',
            'action' => 'Bradarick 2026-08-24 OFF',
            'before' => $this->reviewSummary($bradarickReview),
            'after' => 'OFF pagado, Hubstaff del día fuera del cálculo',
        ];

        $delmark = Employee::query()->find(self::DELMARK_ID);
        $delmarkReview = $this->review($period, $delmark, '2026-08-20');
        $rows[] = [
            'employee_id' => self::DELMARK_ID,
            'employee' => $delmark?->name ?? 'No encontrado',
            'action' => 'Delmark 2026-08-20 confirmar',
            'before' => $this->reviewSummary($delmarkReview).' | '.$this->overtimeSummary($period, $delmark),
            'after' => 'Sin modificación automática',
        ];

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function apply(PayrollPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $this->ensureAugustSecondHalfPeriod($period);

            foreach ($this->targetEmployeeIds() as $employeeId) {
                $employee = Employee::query()->findOrFail($employeeId);
                $this->applyForEmployee($period, $employee);
                app(PayrollCalculationService::class)->recalculateEmployeePayrollResult($period, $employee);
            }

            return $this->preview($period);
        });
    }

    public function applyForPeriod(PayrollPeriod $period): void
    {
        if (! $this->isAugustSecondHalfPeriod($period) || $period->status === 'cerrado') {
            return;
        }

        Employee::query()
            ->whereIn('id', $this->targetEmployeeIds())
            ->get()
            ->each(fn (Employee $employee): bool => $this->applyForEmployee($period, $employee));
    }

    public function applyForEmployee(PayrollPeriod $period, Employee $employee): bool
    {
        if (! $this->isAugustSecondHalfPeriod($period)) {
            return false;
        }

        if (in_array((int) $employee->id, self::TEN_HOUR_EMPLOYEE_IDS, true)) {
            foreach (self::TEN_HOUR_DATES as $date) {
                $this->applyTenPayableHours($period, $employee, $date);
            }

            return true;
        }

        if ((int) $employee->id === self::BRADARICK_ID) {
            $this->applyBradarickDayOff($period, $employee);

            return true;
        }

        return (int) $employee->id === self::DELMARK_ID;
    }

    private function ensureAugustSecondHalfPeriod(PayrollPeriod $period): void
    {
        if (! $this->isAugustSecondHalfPeriod($period)) {
            throw new \RuntimeException('Este ajuste está limitado al período 11 agosto 2026 - 25 agosto 2026.');
        }

        if ($period->status === 'cerrado') {
            throw new \RuntimeException('El período está cerrado y no será modificado.');
        }
    }

    /**
     * @return array<int, int>
     */
    private function targetEmployeeIds(): array
    {
        return [
            ...self::TEN_HOUR_EMPLOYEE_IDS,
            self::BRADARICK_ID,
            self::DELMARK_ID,
        ];
    }

    private function isAugustSecondHalfPeriod(PayrollPeriod $period): bool
    {
        return (bool) $period->starts_at?->isSameDay('2026-08-11')
            && (bool) $period->ends_at?->isSameDay('2026-08-25');
    }

    private function applyTenPayableHours(PayrollPeriod $period, Employee $employee, string $date): void
    {
        $review = $this->review($period, $employee, $date) ?? new DailyTimeReview([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => $date,
        ]);

        $targetSeconds = 36000;
        $ordinarySeconds = max(
            (int) $review->expected_ordinary_seconds,
            $this->hoursToSeconds((float) ($employee->daily_hours ?: 8)),
        );
        $overtimeSeconds = max($targetSeconds - $ordinarySeconds, 0);
        $hubstaffSeconds = max((int) $review->hubstaff_total_seconds, 0);
        $paidNotTrackedSeconds = max($targetSeconds - $hubstaffSeconds, 0);
        $expectedHubstaffSeconds = $hubstaffSeconds > 0 ? min($hubstaffSeconds, $targetSeconds) : $targetSeconds;

        $review->fill([
            'scheduled_work_day' => true,
            'expected_seconds' => $ordinarySeconds,
            'expected_ordinary_seconds' => $ordinarySeconds,
            'assigned_overtime_seconds' => $overtimeSeconds,
            'preassigned_overtime_seconds' => $overtimeSeconds,
            'additional_overtime_seconds' => 0,
            'assigned_overtime_fulfilled' => true,
            'expected_paid_seconds' => $targetSeconds,
            'expected_hubstaff_seconds' => $expectedHubstaffSeconds,
            'paid_day_off' => false,
            'paid_time_not_tracked_seconds' => $paidNotTrackedSeconds,
            'justified_absence_seconds' => 0,
            'unjustified_absence_seconds' => 0,
            'possible_overtime_seconds' => $overtimeSeconds,
            'approved_overtime_seconds' => 0,
            'payable_seconds' => $targetSeconds,
            'difference_seconds' => $hubstaffSeconds - $expectedHubstaffSeconds,
            'status' => 'revisado_supervisor',
            'supervisor_comment' => $this->appendComment(
                $this->removeComment($review->supervisor_comment, self::TEN_HOUR_COMMENT),
                self::TEN_HOUR_COMMENT,
            ),
        ]);
        $review->save();
    }

    private function applyBradarickDayOff(PayrollPeriod $period, Employee $employee): void
    {
        HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', '2026-08-24')
            ->where('active', true)
            ->update(['active' => false]);

        $review = $this->review($period, $employee, '2026-08-24') ?? new DailyTimeReview([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-08-24',
        ]);
        $payableSeconds = $this->hoursToSeconds((float) ($employee->daily_hours ?: 8));

        $review->fill([
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
            'payable_seconds' => $payableSeconds,
            'difference_seconds' => 0,
            'status' => 'revisado_supervisor',
            'supervisor_comment' => self::BRADARICK_OFF_COMMENT,
        ]);
        $review->save();
    }

    private function review(PayrollPeriod $period, ?Employee $employee, string $date): ?DailyTimeReview
    {
        if (! $employee) {
            return null;
        }

        return DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();
    }

    private function overtimeSummary(PayrollPeriod $period, ?Employee $employee): string
    {
        if (! $employee) {
            return 'Sin empleado';
        }

        $amount = $employee->payrollOvertimeAdjustments()
            ->where('payroll_period_id', $period->id)
            ->where('active', true)
            ->sum('amount');

        return 'Ajustes OT '.$this->money((float) $amount);
    }

    private function reviewSummary(?DailyTimeReview $review): string
    {
        if (! $review) {
            return 'Sin revisión';
        }

        return sprintf(
            'Hubstaff %.2fh, pagable %.2fh, OFF %s, faltante %.2fh, idle %.2fh',
            ((int) $review->hubstaff_total_seconds) / 3600,
            ((int) $review->payable_seconds) / 3600,
            $review->paid_day_off ? 'sí' : 'no',
            ((int) $review->unjustified_absence_seconds) / 3600,
            ((int) $review->hubstaff_idle_seconds) / 3600,
        );
    }

    private function hoursToSeconds(float $hours): int
    {
        return max((int) round($hours * 3600), 0);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2);
    }

    private function removeComment(?string $comment, string $line): ?string
    {
        $cleaned = collect(preg_split('/\R/', (string) $comment))
            ->reject(fn (string $commentLine): bool => trim($commentLine) === $line)
            ->implode("\n");

        return filled(trim($cleaned)) ? trim($cleaned) : null;
    }

    private function appendComment(?string $comment, string $line): string
    {
        return trim(collect([$comment, $line])
            ->filter(fn (?string $value): bool => filled($value))
            ->implode("\n"));
    }
}
