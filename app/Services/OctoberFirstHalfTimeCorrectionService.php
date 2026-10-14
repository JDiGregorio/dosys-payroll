<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OctoberFirstHalfTimeCorrectionService
{
    private const OFF = [
        'Wilman%Agurcia' => ['2026-09-29'],
        'Emely%Mejia%' => ['2026-10-02'],
        '%Edwin%Cruz%' => ['2026-10-04', '2026-10-05', '2026-10-06'],
    ];

    private const ELALF = 'Elalf Shamir Dominguez Pineda';

    private const MARCO = 'Marco Antonio Lara';

    private const ELALF_DAYS = ['2026-09-26' => '07:27', '2026-10-03' => '06:11', '2026-10-10' => '08:04'];

    private const MARCO_DAYS = [
        '2026-09-27' => '06:28', '2026-09-28' => '09:09', '2026-09-29' => '07:48',
        '2026-09-30' => '07:49', '2026-10-01' => '07:55', '2026-10-02' => '09:44',
        '2026-10-03' => '06:15', '2026-10-05' => '07:40', '2026-10-06' => '08:33',
        '2026-10-07' => '07:43', '2026-10-08' => '09:07', '2026-10-09' => '07:29',
        '2026-10-10' => '07:18',
    ];

    public function isElalfPeriod(PayrollPeriod $period, Employee $employee): bool
    {
        return $this->isTarget($period) && $employee->name === self::ELALF;
    }

    public function isMarcoPeriod(PayrollPeriod $period, Employee $employee): bool
    {
        return $this->isTarget($period) && $employee->name === self::MARCO;
    }

    public function adjustExpectation(DailyTimeReview $review, Employee $employee): void
    {
        if (! $review->payrollPeriod || ! $this->isTarget($review->payrollPeriod)) {
            return;
        }

        $date = $review->date->toDateString();
        if ($employee->name === self::ELALF && isset(self::ELALF_DAYS[$date])) {
            $review->scheduled_work_day = true;
            $review->expected_ordinary_seconds = 28800;
            $review->expected_seconds = 28800;
            $review->expected_paid_seconds = 28800 + (int) $review->preassigned_overtime_seconds;
            $review->paid_time_not_tracked_seconds = 0;
            $review->expected_hubstaff_seconds = $review->expected_paid_seconds;
        }

        if ($employee->name === self::MARCO && $review->date->isWeekend()) {
            $review->scheduled_work_day = false;
            $review->expected_ordinary_seconds = 0;
            $review->expected_seconds = 0;
            $review->expected_paid_seconds = (int) $review->preassigned_overtime_seconds;
            $review->paid_time_not_tracked_seconds = 0;
            $review->expected_hubstaff_seconds = $review->expected_paid_seconds;
        }

        if ($employee->name === self::MARCO && isset(self::MARCO_DAYS[$date])) {
            $review->paid_time_not_tracked_seconds = 0;
            $review->expected_hubstaff_seconds = $review->expected_paid_seconds;
        }
    }

    public function preview(PayrollPeriod $period): Collection
    {
        $this->assertOpen($period);
        $rows = collect();
        foreach (self::OFF as $name => $dates) {
            foreach ($dates as $date) {
                $rows->push($this->row($period, $name, $date, 'OFF', null));
            }
        }
        foreach (self::ELALF_DAYS as $date => $time) {
            $rows->push($this->row($period, self::ELALF, $date, '9h pagables; faltante justificado', $time));
        }
        foreach (self::MARCO_DAYS as $date => $time) {
            $rows->push($this->row($period, self::MARCO, $date,
                in_array($date, ['2026-09-27', '2026-10-03', '2026-10-10'], true)
                    ? 'OT fin de semana' : '8h ordinarias; solo OT registrado', $time));
        }

        return $rows;
    }

    public function apply(PayrollPeriod $period): int
    {
        return DB::transaction(function () use ($period): int {
            $locked = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $rows = $this->preview($locked);
            $changed = 0;
            foreach ($rows as $row) {
                $review = DailyTimeReview::query()->lockForUpdate()->findOrFail($row['review_id']);
                if ($row['action'] === 'OFF') {
                    HubstaffTimeEntry::query()->where('payroll_period_id', $locked->id)
                        ->where('employee_id', $row['employee_id'])->whereDate('date', $row['date'])
                        ->whereIn('source_provider', ['trackabi', 'trackabi_api'])
                        ->where('active', true)->update(['active' => false]);
                    if (! $review->paid_day_off || $review->verified_tracked_seconds !== 0) {
                        $review->fill([
                            'paid_day_off' => true, 'off_worked' => false, 'off_worked_verified_seconds' => 0,
                            'status' => $review->status === 'pendiente' ? 'revisado_supervisor' : $review->status,
                            'verified_tracked_seconds' => 0,
                            'verified_tracked_source' => 'period_10_off_exclusion',
                            'verified_tracked_note' => 'Tiempo excluido del pago; registro original conservado inactivo.',
                            'verified_tracked_at' => now(), 'justified_absence_seconds' => 0,
                            'unjustified_absence_seconds' => 0, 'estimated_lost_seconds' => 0,
                            'supervisor_adjustment_seconds' => 0,
                        ])->save();
                        $changed++;
                    }
                    continue;
                }

                if (! $review->hasTrackabiTimer()) {
                    throw new RuntimeException("Falta Trackabi activo para {$row['employee']} el {$row['date']}.");
                }
                if ($review->paid_day_off) {
                    throw new RuntimeException("{$row['employee']} tiene {$row['date']} marcado OFF; revisar antes de sustituirlo por tiempo trabajado.");
                }
                $verified = $row['verified_seconds'];
                $isMarco = $row['employee'] === self::MARCO;
                $weekend = $review->date->isWeekend();
                $ordinary = $isMarco && $weekend ? 0 : 28800;
                $justified = max($ordinary + ($isMarco ? 0 : 3600) - $verified, 0);
                if ($review->verified_tracked_seconds === $verified
                    && (int) $review->justified_absence_seconds === $justified
                    && (int) $review->paid_time_not_tracked_seconds === 0) {
                    continue;
                }
                if (! $isMarco) {
                    $review->preassigned_overtime_seconds = 3600;
                }
                $this->adjustExpectation($review, $review->employee);
                $review->fill([
                    'verified_tracked_seconds' => $verified,
                    'verified_tracked_source' => 'manual_trackabi_verification',
                    'verified_tracked_note' => 'Horas verificadas para el periodo 10; timer y CSV originales intactos.',
                    'verified_tracked_at' => now(),
                    'paid_time_not_tracked_seconds' => 0,
                    'justified_absence_seconds' => $justified,
                    'unjustified_absence_seconds' => 0,
                    'estimated_lost_seconds' => $justified,
                    'supervisor_adjustment_seconds' => 0,
                    'assigned_overtime_fulfilled' => false,
                    'lost_time_source' => 'manual_trackabi_verification',
                    'status' => $review->status === 'pendiente' ? 'revisado_supervisor' : $review->status,
                ])->save();
                $changed++;
            }

            if ($changed > 0) {
                foreach ($rows->pluck('employee_id')->unique() as $id) {
                    app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($locked, Employee::findOrFail($id));
                }
            }

            return $changed;
        });
    }

    private function row(PayrollPeriod $period, string $name, string $date, string $action, ?string $time): array
    {
        $employee = Employee::query()->where('name', 'like', $name)->sole();
        $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->whereDate('date', $date)->sole();
        $parts = $time === null ? null : array_map('intval', explode(':', $time));

        return ['review_id' => $review->id, 'employee_id' => $employee->id,
            'employee' => $employee->name, 'date' => $date, 'action' => $action,
            'raw_seconds' => (int) $review->hubstaff_total_seconds,
            'verified_seconds' => $parts === null ? null : $parts[0] * 3600 + $parts[1] * 60,
            'status' => $review->status];
    }

    private function isTarget(PayrollPeriod $period): bool
    {
        return (int) $period->id === 10 && $period->starts_at->toDateString() === '2026-09-26'
            && $period->ends_at->toDateString() === '2026-10-10';
    }

    private function assertOpen(PayrollPeriod $period): void
    {
        if (! $this->isTarget($period) || $period->status === 'cerrado') {
            throw new RuntimeException('Se requiere el periodo abierto 10 del 26/09 al 10/10 de 2026.');
        }
    }
}
