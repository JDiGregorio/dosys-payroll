<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SeptemberSecondHalfTimeCorrectionService
{
    private const ELALF = 'Elalf Shamir Dominguez Pineda';

    private const MARCO = 'Marco Antonio Lara';

    private const VERIFIED_TIMES = [
        self::ELALF => [
            '2026-09-12' => '08:54',
            '2026-09-19' => '08:51',
        ],
        self::MARCO => [
            '2026-09-11' => '08:00',
            '2026-09-12' => '08:00',
            '2026-09-13' => '01:38',
            '2026-09-14' => '09:36',
            '2026-09-15' => '08:39',
            '2026-09-16' => '06:14',
            '2026-09-17' => '08:00',
            '2026-09-18' => '07:41',
            '2026-09-19' => '02:00',
            '2026-09-20' => '05:22',
            '2026-09-21' => '08:29',
            '2026-09-22' => '07:45',
            '2026-09-23' => '07:49',
            '2026-09-24' => '07:45',
            '2026-09-25' => '09:49',
        ],
    ];

    public function isMarcoPeriod(PayrollPeriod $period, Employee $employee): bool
    {
        return $this->isTargetPeriod($period) && $employee->name === self::MARCO;
    }

    public function adjustExpectation(DailyTimeReview $review, Employee $employee): void
    {
        if ((int) $review->payroll_period_id !== 9) {
            return;
        }

        $date = $review->date->toDateString();
        if ($date < '2026-09-11' || $date > '2026-09-25') {
            return;
        }

        if ($employee->name === self::ELALF && in_array($date, ['2026-09-12', '2026-09-19'], true)) {
            $review->scheduled_work_day = true;
            $review->expected_ordinary_seconds = 28800;
            $review->expected_seconds = 28800;
            $review->expected_paid_seconds = 28800 + (int) $review->preassigned_overtime_seconds;
            $review->expected_hubstaff_seconds = $review->expected_paid_seconds;
            $review->paid_time_not_tracked_seconds = 0;
        }

        if ($employee->name === self::MARCO && $review->date->isWeekend()) {
            $review->scheduled_work_day = false;
            $review->expected_ordinary_seconds = 0;
            $review->expected_seconds = 0;
            $review->expected_paid_seconds = (int) $review->preassigned_overtime_seconds;
            $review->expected_hubstaff_seconds = $review->expected_paid_seconds;
            $review->paid_time_not_tracked_seconds = 0;
        }
    }

    public function preview(PayrollPeriod $period): Collection
    {
        $this->assertOpenTargetPeriod($period);

        return collect(self::VERIFIED_TIMES)->flatMap(function (array $days, string $name) use ($period): array {
            $employee = Employee::query()->where('name', $name)->sole();

            return collect($days)->map(function (string $time, string $date) use ($period, $employee): array {
                $review = DailyTimeReview::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->whereDate('date', $date)
                    ->sole();

                if (! $review->hasTrackabiTimer()) {
                    throw new RuntimeException("Falta un registro Trackabi activo para {$employee->name} en {$date}.");
                }

                [$hours, $minutes] = array_map('intval', explode(':', $time));

                return [
                    'review_id' => $review->id,
                    'employee_id' => $employee->id,
                    'employee' => $employee->name,
                    'date' => $date,
                    'raw_seconds' => (int) $review->hubstaff_total_seconds,
                    'verified_seconds' => $hours * 3600 + $minutes * 60,
                    'current_verified_seconds' => $review->verified_tracked_seconds,
                    'status' => $review->status,
                    'justified_seconds' => (int) $review->justified_absence_seconds,
                ];
            })->values()->all();
        })->values();
    }

    public function apply(PayrollPeriod $period): int
    {
        $rows = $this->preview($period);

        return DB::transaction(function () use ($period, $rows): int {
            $this->assertOpenTargetPeriod($period->fresh());
            $changed = 0;

            foreach ($rows as $row) {
                $review = DailyTimeReview::query()->lockForUpdate()->findOrFail($row['review_id']);
                if ((int) $review->hubstaff_total_seconds !== $row['raw_seconds']) {
                    throw new RuntimeException("El registro de {$row['employee']} en {$row['date']} cambió durante la corrección.");
                }

                if ($review->verified_tracked_seconds === $row['verified_seconds']) {
                    continue;
                }

                $review->update([
                    'verified_tracked_seconds' => $row['verified_seconds'],
                    'verified_tracked_source' => 'manual_trackabi_verification',
                    'verified_tracked_note' => 'Horas verificadas en Trackabi para la planilla del 11 al 25 de septiembre de 2026.',
                    'verified_tracked_at' => now(),
                ]);
                $changed++;
            }

            if ($changed === 0) {
                return 0;
            }

            foreach ([self::ELALF, self::MARCO] as $name) {
                $employee = Employee::query()->where('name', $name)->sole();
                app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($period, $employee);
            }

            return $changed;
        });
    }

    private function isTargetPeriod(PayrollPeriod $period): bool
    {
        return (int) $period->id === 9
            && $period->starts_at->toDateString() === '2026-09-11'
            && $period->ends_at->toDateString() === '2026-09-25';
    }

    private function assertOpenTargetPeriod(PayrollPeriod $period): void
    {
        if (! $this->isTargetPeriod($period) || $period->status === 'cerrado') {
            throw new RuntimeException('Se requiere el período abierto 9 del 11 al 25 de septiembre de 2026.');
        }
    }
}
