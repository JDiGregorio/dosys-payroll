<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackabiDayExclusion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OctoberSecondHalfTimeCorrectionService
{
    private const DAYS = [
        ['Seily%Ortiz%', '2026-10-20', 'off'],
        ['Kelvin%Rivera%', '2026-10-24', 'nine_hours'],
        ['Elalf%Dominguez%', '2026-10-17', 'elalf_justified'],
        ['Elalf%Dominguez%', '2026-10-24', 'elalf_unjustified'],
        ['Francisco%Bejarano%', '2026-10-17', 'medical'],
        ['Francisco%Bejarano%', '2026-10-18', 'off'],
    ];

    private const EXPECTED_STATES = [
        'Seily%Ortiz%|2026-10-20' => ['pendiente', 0],
        'Kelvin%Rivera%|2026-10-24' => ['pendiente', 0],
        'Elalf%Dominguez%|2026-10-17' => ['revisado_supervisor', 0],
        'Elalf%Dominguez%|2026-10-24' => ['pendiente', 0],
        'Francisco%Bejarano%|2026-10-17' => ['revisado_supervisor', -22020],
        'Francisco%Bejarano%|2026-10-18' => ['pendiente', 0],
    ];

    public function adjustExpectation(DailyTimeReview $review, Employee $employee): void
    {
        if (! $review->payrollPeriod || ! $this->isTarget($review->payrollPeriod)) {
            return;
        }
        if ($review->verified_tracked_source !== 'period_11_expectation') {
            return;
        }
        $date = $review->date->toDateString();
        $kelvin = str_contains($employee->name, 'Kelvin') && str_contains($employee->name, 'Rivera');
        $elalf = str_contains($employee->name, 'Elalf') && str_contains($employee->name, 'Dominguez');
        if (($kelvin && $date === '2026-10-24')
            || ($elalf && in_array($date, ['2026-10-17', '2026-10-24'], true))) {
            $review->scheduled_work_day = true;
            $review->expected_ordinary_seconds = 28800;
            $review->expected_seconds = 28800;
            $review->preassigned_overtime_seconds = 3600;
            $review->assigned_overtime_seconds = 3600;
            $review->expected_paid_seconds = 32400;
            if ($elalf) {
                $review->paid_time_not_tracked_seconds = 0;
            }
            $review->expected_hubstaff_seconds = 32400 - (int) $review->paid_time_not_tracked_seconds;
        }
    }

    public function preview(PayrollPeriod $period): array
    {
        $this->assertOpen($period);

        return array_map(function (array $day) use ($period): array {
            [$name, $date, $action] = $day;
            $employee = Employee::query()->where('name', 'like', $name)->sole();
            $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('date', $date)->sole();

            return ['employee_id' => $employee->id, 'employee' => $employee->name, 'pattern' => $name,
                'review_id' => $review->id, 'date' => $date, 'action' => $action,
                'status' => $review->status, 'current_payable_seconds' => (int) $review->payable_seconds,
                'measured_seconds' => $review->trackabiActivity?->measured_activity_seconds];
        }, self::DAYS);
    }

    public function apply(PayrollPeriod $period): int
    {
        return DB::transaction(function () use ($period): int {
            $locked = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $rows = $this->preview($locked);
            $affected = [];
            $changed = 0;
            foreach ($rows as $row) {
                $review = DailyTimeReview::query()->lockForUpdate()->findOrFail($row['review_id']);
                $employee = $review->employee;
                $action = $row['action'];
                $date = $row['date'];
                $alreadyApplied = in_array($action, ['off', 'medical'], true)
                    ? TrackabiDayExclusion::query()->where([
                        'payroll_period_id' => $locked->id, 'employee_id' => $employee->id, 'date' => $date,
                    ])->exists()
                    : $review->verified_tracked_source === 'period_11_expectation';
                if ($alreadyApplied) {
                    continue;
                }
                [$expectedStatus, $expectedAdjustment] = self::EXPECTED_STATES[$row['pattern'].'|'.$date];
                if ($review->status !== $expectedStatus || $review->paid_day_off
                    || (int) $review->justified_absence_seconds !== 0
                    || (int) $review->supervisor_adjustment_seconds !== $expectedAdjustment) {
                    throw new RuntimeException("La revisión de {$row['employee']} {$date} cambió desde la copia local. No se aplicó ningún ajuste.");
                }
                if (in_array($action, ['off', 'medical'], true)) {
                    $reason = $action === 'medical'
                        ? 'Incapacidad médica; ausencia justificada.'
                        : 'Día OFF confirmado; tiempo Trackabi excluido.';
                    $key = ['payroll_period_id' => $locked->id, 'employee_id' => $employee->id, 'date' => $date];
                    TrackabiDayExclusion::query()->create($key + [
                        'reason' => $reason,
                        'archived_insights' => TrackabiDailyActivity::query()
                            ->where('payroll_period_id', $locked->id)->where('employee_id', $employee->id)
                            ->whereDate('activity_date', $date)->get()
                            ->map(fn (TrackabiDailyActivity $activity): array => $activity->getAttributes())->all(),
                        'excluded_by' => auth()->id(),
                    ]);
                    $changed++;
                    HubstaffTimeEntry::query()->where('payroll_period_id', $locked->id)
                        ->where('employee_id', $employee->id)->whereDate('date', $date)
                        ->whereIn('source_provider', ['trackabi', 'trackabi_api'])->where('active', true)
                        ->update(['active' => false]);
                    TrackabiDailyActivity::query()->where('payroll_period_id', $locked->id)
                        ->where('employee_id', $employee->id)->whereDate('activity_date', $date)->delete();
                    $changes = $action === 'off'
                        ? ['paid_day_off' => true, 'justified_absence_seconds' => 0]
                        : ['paid_day_off' => false, 'justified_absence_seconds' => 28800];
                    $changes += [
                        'off_worked' => false, 'off_worked_verified_seconds' => 0,
                        'verified_tracked_seconds' => 0,
                        'verified_tracked_source' => 'period_11_day_exclusion',
                        'verified_tracked_note' => $reason,
                        'estimated_lost_seconds' => $action === 'medical' ? 28800 : 0,
                        'supervisor_adjustment_seconds' => 0,
                        'unjustified_absence_seconds' => 0,
                        'status' => 'revisado_supervisor',
                    ];
                    if ($action === 'medical') {
                        $changes['lost_time_source'] = 'missing_timer';
                    }
                    if (! str_contains((string) $review->supervisor_comment, $reason)) {
                        $changes['supervisor_comment'] = trim((string) $review->supervisor_comment.' '.$reason);
                    }
                    $review->fill($changes);
                    if ($review->isDirty()) {
                        $review->save();
                        $changed++;
                    }
                } else {
                    if (! $review->trackabiActivity || $review->paid_day_off) {
                        throw new RuntimeException("Falta actividad Trackabi o el día está OFF: {$row['employee']} {$date}.");
                    }
                    $review->verified_tracked_source = 'period_11_expectation';
                    $review->verified_tracked_note = 'Jornada corregida para el período 11; actividad Trackabi original intacta.';
                    $this->adjustExpectation($review, $employee);
                    if ($action === 'elalf_justified') {
                        $gap = max(32400 - (int) $review->trackabiActivity->measured_activity_seconds, 0);
                        $review->justified_absence_seconds = $gap;
                        $review->estimated_lost_seconds = $gap;
                        $review->unjustified_absence_seconds = 0;
                    } elseif ($action === 'elalf_unjustified') {
                        $gap = max(32400 - (int) $review->trackabiActivity->measured_activity_seconds, 0);
                        $review->justified_absence_seconds = 0;
                        $review->estimated_lost_seconds = $gap;
                        $review->unjustified_absence_seconds = $gap;
                    }
                    if ($review->isDirty()) {
                        $review->save();
                        $changed++;
                    }
                }
                $affected[$employee->id] = $employee;
            }

            if ($changed > 0) {
                foreach ($affected as $employee) {
                    app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($locked, $employee);
                }
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
