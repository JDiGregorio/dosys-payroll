<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\EmployeeAdditionalDeduction;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollBonus;
use App\Models\PayrollDeduction;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackabiDayExclusion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NovemberFirstHalfPayrollAdjustmentsService
{
    private const MARCO_HOURS = [
        '2026-10-26' => 8, '2026-10-27' => 8, '2026-10-28' => 9,
        '2026-10-29' => 9, '2026-10-30' => 9, '2026-10-31' => 4,
        '2026-11-02' => 8, '2026-11-03' => 8, '2026-11-04' => 9,
        '2026-11-05' => 10, '2026-11-06' => 10, '2026-11-07' => 4,
        '2026-11-09' => 8, '2026-11-10' => 9,
    ];

    private const DENISHA_HOURS = [
        '2026-10-29' => 7, '2026-10-30' => 7,
        '2026-11-02' => 8, '2026-11-03' => 8, '2026-11-04' => 8,
        '2026-11-06' => 8, '2026-11-07' => 8, '2026-11-08' => 8,
    ];

    private const DENISHA_OFF = ['2026-10-31', '2026-11-01', '2026-11-05', '2026-11-09', '2026-11-10'];

    private const OFF_DAYS = [
        ['Eduardo%Mahoudeau%', '2026-11-03'],
        ['Delmark%Sanders%', '2026-11-01'],
        ['Seily%Ortiz%', '2026-10-27'],
        ['Francisco%Bejarano%', '2026-10-27'],
        ['Marco%Lara%', '2026-11-01'],
        ['Marco%Lara%', '2026-11-08'],
    ];

    public function __construct(private readonly PayrollCalculationService $payroll) {}

    public function isTarget(PayrollPeriod $period): bool
    {
        return (int) $period->id === 12
            && $period->starts_at->toDateString() === '2026-10-26'
            && $period->ends_at->toDateString() === '2026-11-10';
    }

    public function isMarcoPeriod(PayrollPeriod $period, Employee $employee): bool
    {
        return $this->isTarget($period) && $employee->name === 'Marco Antonio Lara';
    }

    public function isDenishaPeriod(PayrollPeriod $period, Employee $employee): bool
    {
        return $this->isTarget($period) && $employee->name === 'Denisha Marissa Buckley Medina';
    }

    public function paidDenishaDays(Collection $reviews): float
    {
        return (float) $reviews->filter(fn (DailyTimeReview $review): bool =>
            isset(self::DENISHA_HOURS[$review->date->toDateString()])
            || (in_array($review->date->toDateString(), self::DENISHA_OFF, true) && $review->paid_day_off)
        )->count();
    }

    public function adjustExpectation(DailyTimeReview $review, Employee $employee): void
    {
        $period = $review->payrollPeriod;
        if (! $period || ! $this->isTarget($period)) {
            return;
        }

        if ($this->isMarcoPeriod($period, $employee)
            && $review->verified_tracked_source === 'period_12_marco_supervised') {
            $review->paid_time_not_tracked_seconds = 0;
            $review->expected_hubstaff_seconds = (int) $review->expected_paid_seconds;
        }

        if (! $this->isDenishaPeriod($period, $employee)) {
            return;
        }

        $workDay = isset(self::DENISHA_HOURS[$review->date->toDateString()]);
        $review->scheduled_work_day = $workDay;
        $review->expected_ordinary_seconds = $workDay ? 11 * 3600 : 0;
        $review->expected_seconds = $review->expected_ordinary_seconds;
        $review->preassigned_overtime_seconds = 0;
        $review->assigned_overtime_seconds = 0;
        $review->expected_paid_seconds = $workDay ? 11 * 3600 : 0;
        $review->expected_hubstaff_seconds = $review->expected_paid_seconds;
        $review->paid_time_not_tracked_seconds = 0;
    }

    public function preview(PayrollPeriod $period): array
    {
        $this->assertOpen($period);

        return [
            'off_days' => self::OFF_DAYS,
            'marco' => self::MARCO_HOURS,
            'denisha' => ['work' => self::DENISHA_HOURS, 'off' => self::DENISHA_OFF],
            'lunch_deductions' => EmployeeAdditionalDeduction::query()->where('payroll_period_id', $period->id)
                ->where('type', 'other')->whereRaw('LOWER(TRIM(description)) IN (?, ?)', ['almuerzo', 'almuerzos'])->count(),
            'rap_deductions' => EmployeeAdditionalDeduction::query()->where('payroll_period_id', $period->id)
                ->where('type', 'other')->whereRaw('UPPER(TRIM(description)) = ?', ['RAP'])->count(),
            'marketing_bonuses' => PayrollBonus::query()->where('payroll_period_id', $period->id)
                ->where('type', 'other')->whereRaw('LOWER(TRIM(description)) = ?', ['bono de marketing'])->count(),
        ];
    }

    public function apply(PayrollPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $locked = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $this->assertOpen($locked);
            $changed = ['off_days' => 0, 'marco_days' => 0, 'denisha_days' => 0,
                'lunch_deductions' => 0, 'rap_deductions' => 0, 'marketing_bonuses' => 0];
            $affected = [];

            foreach (self::OFF_DAYS as [$pattern, $date]) {
                $employee = Employee::query()->where('name', 'like', $pattern)->sole();
                if ($this->excludeDay($locked, $employee, $date)) {
                    $changed['off_days']++;
                    $affected[$employee->id] = $employee;
                }
            }

            $marco = Employee::query()->where('name', 'Marco Antonio Lara')->sole();
            foreach (self::MARCO_HOURS as $date => $hours) {
                $review = $this->review($locked, $marco, $date);
                if ($review->paid_day_off) {
                    throw new RuntimeException("Marco {$date} está OFF; no se alteró el período.");
                }
                if ($review->verified_tracked_source === 'period_12_marco_supervised') {
                    if ((int) $review->verified_tracked_seconds !== $hours * 3600) {
                        throw new RuntimeException("Marco {$date} ya fue corregido con otro tiempo.");
                    }
                    continue;
                }
                if ((int) $review->justified_absence_seconds !== 0 || (int) $review->supervisor_adjustment_seconds !== 0) {
                    throw new RuntimeException("Marco {$date} tiene un ajuste manual nuevo; revisar antes de aplicar.");
                }
                $review->fill([
                    'verified_tracked_seconds' => $hours * 3600,
                    'verified_tracked_source' => 'period_12_marco_supervised',
                    'verified_tracked_note' => 'Horas supervisadas para el período 12; raw Trackabi preservado.',
                    'verified_tracked_at' => now(),
                    'status' => 'revisado_supervisor',
                ])->save();
                $changed['marco_days']++;
                $affected[$marco->id] = $marco;
            }

            $denisha = Employee::query()->where('name', 'Denisha Marissa Buckley Medina')->sole();
            if (! DailyTimeReview::query()->where('payroll_period_id', $locked->id)
                ->where('employee_id', $denisha->id)->exists()) {
                $this->payroll->regenerateEmployeeDailyReviews($locked, $denisha);
            }
            foreach (self::DENISHA_HOURS as $date => $hours) {
                $review = $this->review($locked, $denisha, $date);
                if ($review->verified_tracked_source === 'period_12_supervised_training') {
                    if ((int) $review->verified_tracked_seconds !== $hours * 3600) {
                        throw new RuntimeException("Denisha {$date} tiene otras horas supervisadas; no se sobrescribieron.");
                    }
                    $regularGap = (11 - $hours) * 3600;
                    $previousGap = (12 - $hours) * 3600;
                    if ((int) $review->justified_absence_seconds > $regularGap
                        && (int) $review->justified_absence_seconds !== $previousGap) {
                        throw new RuntimeException("Denisha {$date} tiene una justificación distinta; revisar antes de retirar OT.");
                    }
                    if ((int) $review->estimated_lost_seconds === $previousGap
                        || (int) $review->justified_absence_seconds === $previousGap) {
                        $review->estimated_lost_seconds = $regularGap;
                        if ((int) $review->justified_absence_seconds === $previousGap) {
                            $review->justified_absence_seconds = $regularGap;
                        }
                        $review->save();
                        $changed['denisha_days']++;
                    }
                    continue;
                }
                if ($review->status !== 'pendiente' || $review->paid_day_off) {
                    throw new RuntimeException("Denisha {$date} ya tiene revisión manual; no se sobrescribió.");
                }
                $review->fill([
                    'verified_tracked_seconds' => $hours * 3600,
                    'verified_tracked_source' => 'period_12_supervised_training',
                    'verified_tracked_note' => 'Tiempo confirmado por supervisión en capacitación; sin Trackabi.',
                    'verified_tracked_at' => now(),
                    'lost_time_source' => 'supervisor_adjustment',
                    'estimated_lost_seconds' => (11 - $hours) * 3600,
                    'justified_absence_seconds' => (11 - $hours) * 3600,
                    'unjustified_absence_seconds' => 0,
                    'status' => 'revisado_supervisor',
                    'supervisor_comment' => 'Jornada de capacitación supervisada; día completo autorizado.',
                ])->save();
                $changed['denisha_days']++;
            }
            foreach (self::DENISHA_OFF as $date) {
                $review = $this->review($locked, $denisha, $date);
                if ($review->paid_day_off) {
                    continue;
                }
                if ($review->status !== 'pendiente') {
                    throw new RuntimeException("Denisha {$date} ya tiene revisión manual; no se sobrescribió.");
                }
                $review->fill(['paid_day_off' => true, 'status' => 'revisado_supervisor',
                    'lost_time_source' => 'supervisor_adjustment',
                    'supervisor_comment' => 'Día OFF confirmado durante capacitación.'])->save();
                $changed['denisha_days']++;
            }
            $affected[$denisha->id] = $denisha;

            $lunch = DeductionType::query()->firstOrCreate(['code' => 'lunch'], [
                'name' => 'Almuerzo', 'default_amount' => 0, 'default_percentage' => 0, 'active' => true,
            ]);
            $rap = DeductionType::query()->where('code', 'rap')->sole();
            foreach (['lunch' => $lunch, 'rap' => $rap] as $kind => $type) {
                $rows = EmployeeAdditionalDeduction::query()->where('payroll_period_id', $locked->id)
                    ->where('type', 'other')
                    ->whereRaw($kind === 'lunch' ? 'LOWER(TRIM(description)) IN (?, ?)' : 'UPPER(TRIM(description)) = ?',
                        $kind === 'lunch' ? ['almuerzo', 'almuerzos'] : ['RAP'])->get();
                foreach ($rows as $row) {
                    DB::table('employee_additional_deductions')->where('id', $row->id)->update(['type' => $kind]);
                    DB::table('payroll_deductions')->where('employee_additional_deduction_id', $row->id)
                        ->update(['deduction_type_id' => $type->id, 'additional_type' => $kind]);
                    $affected[$row->employee_id] = Employee::query()->findOrFail($row->employee_id);
                    $changed[$kind.'_deductions']++;
                }
            }

            $bonuses = PayrollBonus::query()->where('payroll_period_id', $locked->id)
                ->where('type', 'other')->whereRaw('LOWER(TRIM(description)) = ?', ['bono de marketing'])->get();
            foreach ($bonuses as $bonus) {
                if ($bonus->scope_type !== 'employee' || ! $bonus->employee_id) {
                    throw new RuntimeException('Un bono Marketing no es individual; requiere revisión manual.');
                }
                DB::table('payroll_bonuses')->where('id', $bonus->id)->update(['type' => 'marketing']);
                $affected[$bonus->employee_id] = Employee::query()->findOrFail($bonus->employee_id);
                $changed['marketing_bonuses']++;
            }

            foreach ($affected as $employee) {
                $before = PayrollResult::query()->where('payroll_period_id', $locked->id)
                    ->where('employee_id', $employee->id)->first();
                if (in_array($employee->id, [$marco->id, $denisha->id], true)
                    || $this->hasExcludedDay($locked, $employee)) {
                    $this->payroll->recalculateEmployeePreservingManual($locked, $employee);
                } else {
                    $this->payroll->recalculateEmployeePayrollResult($locked, $employee);
                }
                if ($before && ! in_array($employee->id, [$marco->id, $denisha->id], true)
                    && ! $this->hasExcludedDay($locked, $employee)) {
                    $after = $before->fresh();
                    if (round((float) $before->net_amount, 2) !== round((float) $after->net_amount, 2)) {
                        throw new RuntimeException("La reclasificación alteró el neto de {$employee->name}; transacción revertida.");
                    }
                }
            }

            return $changed;
        });
    }

    private function hasExcludedDay(PayrollPeriod $period, Employee $employee): bool
    {
        return TrackabiDayExclusion::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->where('reason', 'Corrección confirmada de día OFF, período 12.')->exists();
    }

    private function excludeDay(PayrollPeriod $period, Employee $employee, string $date): bool
    {
        $review = $this->review($period, $employee, $date);
        $key = ['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'date' => $date];
        if (TrackabiDayExclusion::query()->where($key)->exists()) {
            if (! $review->paid_day_off) {
                throw new RuntimeException("La exclusión {$employee->name} {$date} existe pero no está OFF.");
            }
            return false;
        }
        if ((int) $review->justified_absence_seconds > 0 || (int) $review->supervisor_adjustment_seconds !== 0
            || $review->off_worked) {
            throw new RuntimeException("{$employee->name} {$date} tiene otra decisión manual; no se sobrescribió.");
        }
        TrackabiDayExclusion::query()->create($key + [
            'reason' => 'Corrección confirmada de día OFF, período 12.',
            'archived_insights' => TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('activity_date', $date)->get()
                ->map(fn (TrackabiDailyActivity $row): array => $row->getAttributes())->all(),
            'excluded_by' => auth()->id(),
        ]);
        HubstaffTimeEntry::query()->where('payroll_period_id', $period->id)->where('employee_id', $employee->id)
            ->whereDate('date', $date)->where('source_provider', 'trackabi_api')->update(['active' => false]);
        TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)->where('employee_id', $employee->id)
            ->whereDate('activity_date', $date)->delete();
        $review->fill([
            'paid_day_off' => true, 'off_worked' => false, 'off_worked_verified_seconds' => 0,
            'verified_tracked_seconds' => 0, 'verified_tracked_source' => 'period_12_day_exclusion',
            'verified_tracked_note' => 'Día OFF confirmado; raw Trackabi archivado.',
            'estimated_lost_seconds' => 0, 'supervisor_adjustment_seconds' => 0,
            'justified_absence_seconds' => 0, 'unjustified_absence_seconds' => 0,
            'status' => 'revisado_supervisor',
            'supervisor_comment' => trim((string) $review->supervisor_comment.' Día OFF confirmado.'),
        ])->save();

        return true;
    }

    private function review(PayrollPeriod $period, Employee $employee, string $date): DailyTimeReview
    {
        return DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->whereDate('date', $date)->lockForUpdate()->sole();
    }

    private function assertOpen(PayrollPeriod $period): void
    {
        if (! $this->isTarget($period) || ! in_array($period->status, ['borrador', 'importado', 'en_revision'], true)) {
            throw new RuntimeException('Se requiere el período 12 abierto del 26/10 al 10/11 de 2026.');
        }
    }
}
