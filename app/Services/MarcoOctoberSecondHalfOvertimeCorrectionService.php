<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MarcoOctoberSecondHalfOvertimeCorrectionService
{
    private const DATES = ['2026-10-13', '2026-10-19', '2026-10-20'];

    private const SOURCE = 'period_11_marco_ot_cap';

    public function isMarcoPeriod(PayrollPeriod $period, Employee $employee): bool
    {
        return $this->isTarget($period) && $employee->name === 'Marco Antonio Lara';
    }

    /** @return array{before: string, after: string} */
    public function refreshWorkedDays(PayrollPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $locked = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $this->assertOpen($locked);
            $employee = Employee::query()->where('name', 'Marco Antonio Lara')->sole();
            $result = PayrollResult::query()->where('payroll_period_id', $locked->id)
                ->where('employee_id', $employee->id)->lockForUpdate()->sole();
            if ($result->salary_calculation_method !== 'semi_monthly_fixed_with_deductions') {
                throw new RuntimeException('El método salarial de Marco cambió; no se actualizó el conteo.');
            }
            foreach (self::DATES as $date) {
                $review = DailyTimeReview::query()->where('payroll_period_id', $locked->id)
                    ->where('employee_id', $employee->id)->whereDate('date', $date)->sole();
                if ($review->verified_tracked_source !== self::SOURCE
                    || (int) $review->possible_overtime_seconds !== 3600) {
                    throw new RuntimeException("El OT de Marco {$date} no está corregido; no se actualizó el conteo.");
                }
            }
            $before = $result->getAttributes();
            app(PayrollCalculationService::class)->recalculateEmployeePayrollResult($locked, $employee);
            $after = $result->fresh()->getAttributes();
            $beforeDays = (string) $before['worked_days'];
            $afterDays = (string) $after['worked_days'];
            unset($before['worked_days'], $before['updated_at'], $after['worked_days'], $after['updated_at']);
            if ($before !== $after || round((float) $afterDays, 2) !== 15.0) {
                throw new RuntimeException('El recálculo cambiaría otros importes o no llegaría a 15 días. No se guardó ningún cambio.');
            }

            return ['before' => $beforeDays, 'after' => $afterDays];
        });
    }

    public function adjustExpectation(DailyTimeReview $review, Employee $employee): void
    {
        if ($review->verified_tracked_source !== self::SOURCE
            || $employee->name !== 'Marco Antonio Lara'
            || ! in_array($review->date->toDateString(), self::DATES, true)
            || ! $review->payrollPeriod || ! $this->isTarget($review->payrollPeriod)) {
            return;
        }

        $review->preassigned_overtime_seconds = min((int) $review->preassigned_overtime_seconds, 3600);
        $review->assigned_overtime_seconds = $review->preassigned_overtime_seconds;
        $review->expected_paid_seconds = (int) $review->expected_ordinary_seconds
            + (int) $review->preassigned_overtime_seconds;
        $review->expected_hubstaff_seconds = max(
            (int) $review->expected_paid_seconds - (int) $review->paid_time_not_tracked_seconds, 0,
        );
    }

    public function preview(PayrollPeriod $period): array
    {
        $this->assertOpen($period);
        $employee = Employee::query()->where('name', 'Marco Antonio Lara')->sole();

        return array_map(function (string $date) use ($period, $employee): array {
            $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('date', $date)->sole();

            return [
                'review_id' => $review->id, 'date' => $date, 'employee' => $employee->name,
                'status' => $review->status, 'current_overtime_seconds' => (int) $review->possible_overtime_seconds,
                'proposed_overtime_seconds' => min((int) $review->possible_overtime_seconds, 3600),
                'current_payable_seconds' => (int) $review->payable_seconds,
                'source' => $review->verified_tracked_source,
            ];
        }, self::DATES);
    }

    public function apply(PayrollPeriod $period): int
    {
        return DB::transaction(function () use ($period): int {
            $locked = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $rows = $this->preview($locked);
            $changed = 0;
            foreach ($rows as $row) {
                $review = DailyTimeReview::query()->lockForUpdate()->findOrFail($row['review_id']);
                if ($review->verified_tracked_source === self::SOURCE) {
                    continue;
                }
                if ($review->status !== 'revisado_supervisor' || $review->paid_day_off
                    || (int) $review->justified_absence_seconds !== 0
                    || (int) $review->supervisor_adjustment_seconds !== 0
                    || $review->verified_tracked_seconds !== null
                    || (int) $review->possible_overtime_seconds <= 3600) {
                    throw new RuntimeException("La revisión de Marco {$row['date']} cambió; no se aplicó ningún ajuste.");
                }
                $review->verified_tracked_source = self::SOURCE;
                $review->verified_tracked_note = 'OT entre semana limitado a 1:00; actividad Trackabi original intacta.';
                $this->adjustExpectation($review, $review->employee);
                $review->save();
                $changed++;
            }

            if ($changed > 0) {
                $employee = Employee::query()->where('name', 'Marco Antonio Lara')->sole();
                app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($locked, $employee);
            }

            return $changed;
        });
    }

    private function isTarget(PayrollPeriod $period): bool
    {
        return (int) $period->id === 11
            && $period->starts_at->toDateString() === '2026-10-11'
            && $period->ends_at->toDateString() === '2026-10-25';
    }

    private function assertOpen(PayrollPeriod $period): void
    {
        if (! $this->isTarget($period) || $period->status === 'cerrado') {
            throw new RuntimeException('Se requiere el período abierto 11 del 11/10 al 25/10 de 2026.');
        }
    }
}
