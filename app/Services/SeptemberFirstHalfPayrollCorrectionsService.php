<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeptemberFirstHalfPayrollCorrectionsService
{
    private const SATURDAY_DATE = '2026-09-05';

    private const SATURDAY_NO_WORK_NAMES = [
        'Francisco Bejarano',
        'Ashley Escobar',
        'Julio Chavez',
        'Gabriela Reyes',
        'Samuel Montoya',
        'Edwin Cruz',
        'Wilman Agurcia',
    ];

    private const SATURDAY_DUPLICATE_NINE_HOUR_NAMES = [
        'Alexa Enamorado',
        'Andrea Romero',
        'Angela Lapresta',
        'Annie Hernandez',
        'Bradarick Pavon',
        'Brenden Murillo',
        'Bryan Molina',
        'Carley Dixon',
        'Christian Figueroa',
        'Delmark Sanders',
        'Dereck Aguilar',
        'Diego Miranda',
        'Eduardo Mahoudeau',
        'Elalf Dominguez',
        'Emely Mejia',
        'Ephram Brito',
        'Fredy Oviedo',
        'Hector Benitez',
        'Ilce Miralda',
        'Katherine Najera',
        'Kelvin Rivera',
        'Lourdes Cartagena',
        'Melany Martinez',
        'Moises Molina',
        'Ricardo Portillo',
        'Said Moya',
        'Sandro Aplicano',
        'Seily Ortiz',
        'Sharon Martinez',
        'Sharon Reyes',
        'Shone Nelson',
        'Valery Bermudez',
        'Zelda Brooks',
    ];

    private const ASHLEY_NAME = 'Ashley Escobar';

    private const VICTOR_NAME = 'Victor Vasquez';

    private const MARCO_NAME = 'Marco Lara';

    private const MELANY_NAME = 'Melany Martinez';

    private const ADMIN_NAMES = [
        'Jonathan Garcia',
        'Orely Ramirez',
    ];

    private const SATURDAY_COMMENT = 'Ajuste puntual septiembre 2026: sábado 5 no trabajado, tiempo importado fuera del cálculo.';

    private const PALMETTO_SATURDAY_OFF_COMMENT = 'Ajuste puntual septiembre 2026: Palmetto no trabajó el sábado 5; día marcado como OFF.';

    private const MARCO_REPLACEMENT_OFF_COMMENT = 'Ajuste puntual septiembre 2026: tiempo de reposición removido; día marcado como OFF.';

    private const ADMIN_COMMENT = 'Ajuste administrativo: empleado sin tracker, quincena pagada completa.';

    /**
     * @var array<string, array{tracked: int, paid_not_tracked?: int}>
     */
    private const VICTOR_DAILY_TARGETS = [
        '2026-08-26' => ['tracked' => 36000],
        '2026-08-27' => ['tracked' => 36000],
        '2026-08-28' => ['tracked' => 36000],
        '2026-08-31' => ['tracked' => 36000],
        '2026-09-01' => ['tracked' => 36000],
        '2026-09-02' => ['tracked' => 36000],
        '2026-09-03' => ['tracked' => 36000],
        '2026-09-04' => ['tracked' => 35880],
        '2026-09-08' => ['tracked' => 36000],
        '2026-09-09' => ['tracked' => 36000],
        '2026-09-10' => ['tracked' => 35940],
    ];

    /**
     * @var array<string, array{tracked: int, paid_not_tracked?: int}>
     */
    private const MARCO_DAILY_TARGETS = [
        '2026-08-26' => ['tracked' => 29580, 'paid_not_tracked' => 4500],
        '2026-08-27' => ['tracked' => 33720],
        '2026-08-28' => ['tracked' => 29220, 'paid_not_tracked' => 4500],
        '2026-08-31' => ['tracked' => 35760],
        '2026-09-01' => ['tracked' => 28860, 'paid_not_tracked' => 4500],
        '2026-09-02' => ['tracked' => 64920, 'paid_not_tracked' => 4500],
        '2026-09-03' => ['tracked' => 32400, 'paid_not_tracked' => 3600],
        '2026-09-04' => ['tracked' => 29280, 'paid_not_tracked' => 4500],
        '2026-09-08' => ['tracked' => 29820, 'paid_not_tracked' => 4500],
        '2026-09-09' => ['tracked' => 28920, 'paid_not_tracked' => 4500],
        '2026-09-10' => ['tracked' => 28800, 'paid_not_tracked' => 4500],
    ];

    private const MARCO_REPLACEMENT_OFF_DATES = [
        '2026-08-30',
        '2026-09-06',
    ];

    private const MELANY_LAST_WORK_DATE = '2026-09-02';

    public function __construct(
        private readonly PayrollCalculationService $payrollCalculationService,
        private readonly ScheduleExpectationService $scheduleExpectationService,
    ) {}

    /**
     * @return array{actions: array<int, array<string, mixed>>, outside_saturday_entries: array<int, array<string, mixed>>}
     */
    public function preview(PayrollPeriod $period): array
    {
        $this->ensureSeptemberFirstHalfPeriod($period);

        $rows = [];
        $saturdayEmployees = $this->saturdayNoWorkEmployees();

        foreach ($saturdayEmployees as $employee) {
            $isPalmetto = $this->isPalmettoEmployee($employee);
            $rows[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->name,
                'action' => 'Remover tiempo sábado 5',
                'before' => $this->reviewSummary($this->review($period, $employee, self::SATURDAY_DATE)),
                'after' => $isPalmetto
                    ? 'Sin horas activas, OFF pagado por día libre de Palmetto.'
                    : 'Sin horas activas, sin OFF, no pagado salvo revisión posterior.',
            ];
        }

        foreach ([self::VICTOR_NAME => self::VICTOR_DAILY_TARGETS, self::MARCO_NAME => self::MARCO_DAILY_TARGETS] as $name => $targets) {
            if (! $employee = $this->employeeByName($name)) {
                continue;
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->name,
                'action' => $name.': horas Trackabi ajustadas',
                'before' => 'Revisiones actuales del período',
                'after' => count($targets).' días corregidos a 8h ordinarias + 2h extra diaria.',
            ];
        }

        if ($marco = $this->employeeByName(self::MARCO_NAME)) {
            $rows[] = [
                'employee_id' => $marco->id,
                'employee' => $marco->name,
                'action' => 'Marco: domingos de reposición',
                'before' => 'Tiempo activo en 30 agosto / 6 septiembre si existe',
                'after' => 'Registros desactivados y días marcados OFF.',
            ];
        }

        if ($melany = $this->employeeByName(self::MELANY_NAME)) {
            $rows[] = [
                'employee_id' => $melany->id,
                'employee' => $melany->name,
                'action' => 'Melany: fin de relación 2 septiembre',
                'before' => 'Tiempo activo posterior al 2 septiembre si existe',
                'after' => 'Registros posteriores desactivados y sin pago desde el 3 septiembre.',
            ];
        }

        foreach ($this->saturdayDuplicateNineHourEmployees() as $employee) {
            $rows[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->name,
                'action' => 'Remover 9h Sin proyecto sábado 5',
                'before' => $this->saturdayEntriesSummary($period, $employee),
                'after' => 'Se conserva el tiempo real y se excluye solo el bloque Sin proyecto 9:00.',
            ];
        }

        if ($ashley = $this->employeeByName(self::ASHLEY_NAME)) {
            $rows[] = [
                'employee_id' => $ashley->id,
                'employee' => $ashley->name,
                'action' => 'Ashley: jornada 8h y reset de revisiones',
                'before' => "daily_hours={$ashley->daily_hours}, revisiones=".$this->reviewCount($period, $ashley),
                'after' => 'daily_hours=8.00 y revisiones regeneradas para nueva revisión.',
            ];
        }

        foreach ($this->adminEmployees() as $employee) {
            $result = $employee->payrollResults()
                ->where('payroll_period_id', $period->id)
                ->first();

            $rows[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->name,
                'action' => 'Administrativo sin tracker',
                'before' => $result
                    ? 'perdido '.$this->hours((int) $result->lost_time_seconds).', neto '.number_format((float) $result->net_amount, 2)
                    : 'Sin resultado',
                'after' => 'Quincena completa por ausencia justificada administrativa.',
            ];
        }

        return [
            'actions' => $rows,
            'outside_saturday_entries' => $this->outsideSaturdayEntries($period)->all(),
        ];
    }

    /**
     * @return array{actions: array<int, array<string, mixed>>, outside_saturday_entries: array<int, array<string, mixed>>}
     */
    public function apply(PayrollPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $this->ensureSeptemberFirstHalfPeriod($period);

            $affected = collect();
            $regenerated = collect();

            foreach ($this->saturdayNoWorkEmployees() as $employee) {
                $this->applySaturdayNoWork($period, $employee);
                $affected->push($employee->id);
            }

            foreach ($this->saturdayDuplicateNineHourEmployees() as $employee) {
                if ($this->applySaturdayDuplicateNineHourCleanup($period, $employee) > 0) {
                    $this->payrollCalculationService->regenerateEmployeeDailyReviews($period, $employee);
                    $affected->push($employee->id);
                    $regenerated->push($employee->id);
                }
            }

            if ($ashley = $this->employeeByName(self::ASHLEY_NAME)) {
                $this->applyAshleyCorrection($period, $ashley);
                $affected->push($ashley->id);
            }

            foreach ($this->adminEmployees() as $employee) {
                $this->applyAdministrativeFullPay($period, $employee);
                $affected->push($employee->id);
            }

            foreach ([self::VICTOR_NAME, self::MARCO_NAME, self::MELANY_NAME] as $name) {
                if ($employee = $this->employeeByName($name)) {
                    $this->applyEmployeeSpecificCorrections($period, $employee);
                    $affected->push($employee->id);
                }
            }

            Employee::query()
                ->whereIn('id', $affected->unique()->values()->all())
                ->whereNotIn('id', $regenerated->unique()->values()->all())
                ->get()
                ->each(fn (Employee $employee): null => $this->payrollCalculationService->recalculateEmployeePayrollResult($period, $employee));

            return $this->preview($period);
        });
    }

    public function applyForPeriod(PayrollPeriod $period): void
    {
        if (! $this->isSeptemberFirstHalfPeriod($period) || $period->status === 'cerrado') {
            return;
        }

        $this->saturdayNoWorkEmployees()
            ->each(fn (Employee $employee): bool => $this->applyForEmployee($period, $employee));

        $this->saturdayDuplicateNineHourEmployees()
            ->each(fn (Employee $employee): bool => $this->applyForEmployee($period, $employee));

        $this->adminEmployees()
            ->each(fn (Employee $employee): bool => $this->applyForEmployee($period, $employee));

        if ($ashley = $this->employeeByName(self::ASHLEY_NAME)) {
            $this->applyAshleyHoursOnly($ashley);
        }

        foreach ([self::VICTOR_NAME, self::MARCO_NAME, self::MELANY_NAME] as $name) {
            if ($employee = $this->employeeByName($name)) {
                $this->applyEmployeeSpecificCorrections($period, $employee);
            }
        }
    }

    public function applyForEmployee(PayrollPeriod $period, Employee $employee): bool
    {
        if (! $this->isSeptemberFirstHalfPeriod($period)) {
            return false;
        }

        $applied = false;

        if ($this->isSaturdayNoWorkEmployee($employee)) {
            $this->applySaturdayNoWork($period, $employee);
            $applied = true;
        }

        if ($this->isSaturdayDuplicateNineHourEmployee($employee)) {
            $applied = $this->applySaturdayDuplicateNineHourCleanup($period, $employee) > 0 || $applied;
        }

        if ($this->isAdminEmployee($employee)) {
            $this->applyAdministrativeFullPay($period, $employee);
            $applied = true;
        }

        if ($this->matchesName($employee, self::ASHLEY_NAME)) {
            $this->applyAshleyHoursOnly($employee);
            $applied = true;
        }

        if ($this->applyEmployeeSpecificCorrections($period, $employee)) {
            $applied = true;
        }

        return $applied;
    }

    private function ensureSeptemberFirstHalfPeriod(PayrollPeriod $period): void
    {
        if (! $this->isSeptemberFirstHalfPeriod($period)) {
            throw new \RuntimeException('Este ajuste está limitado al período 26 agosto 2026 - 10 septiembre 2026.');
        }

        if ($period->status === 'cerrado') {
            throw new \RuntimeException('El período está cerrado y no será modificado.');
        }
    }

    private function isSeptemberFirstHalfPeriod(PayrollPeriod $period): bool
    {
        return (bool) $period->starts_at?->isSameDay('2026-08-26')
            && (bool) $period->ends_at?->isSameDay('2026-09-10');
    }

    private function applySaturdayNoWork(PayrollPeriod $period, Employee $employee): void
    {
        HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', self::SATURDAY_DATE)
            ->where('active', true)
            ->update(['active' => false]);

        $review = $this->review($period, $employee, self::SATURDAY_DATE) ?? new DailyTimeReview([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => self::SATURDAY_DATE,
        ]);
        $expectation = $this->scheduleExpectationService->forDate($employee, Carbon::parse(self::SATURDAY_DATE));
        $ordinarySeconds = (int) $expectation['expected_ordinary_seconds'];
        $forcePaidDayOff = $this->isPalmettoEmployee($employee);
        $preservePaidDayOff = (bool) $review->paid_day_off || $forcePaidDayOff;
        $payableSeconds = $preservePaidDayOff
            ? $this->paidDayOffSeconds($employee, $ordinarySeconds, (bool) $expectation['scheduled_work_day'], (string) $expectation['schedule_type'])
            : 0;
        $comment = $forcePaidDayOff
            ? $this->appendComment($review->supervisor_comment, self::PALMETTO_SATURDAY_OFF_COMMENT)
            : ($preservePaidDayOff ? $review->supervisor_comment : self::SATURDAY_COMMENT);

        $review->fill([
            'scheduled_work_day' => (bool) $expectation['scheduled_work_day'],
            'expected_seconds' => $ordinarySeconds,
            'expected_ordinary_seconds' => $ordinarySeconds,
            'assigned_overtime_seconds' => 0,
            'preassigned_overtime_seconds' => 0,
            'additional_overtime_seconds' => 0,
            'assigned_overtime_fulfilled' => false,
            'expected_paid_seconds' => $ordinarySeconds,
            'expected_hubstaff_seconds' => $ordinarySeconds,
            'hubstaff_total_seconds' => 0,
            'hubstaff_regular_seconds' => 0,
            'hubstaff_idle_seconds' => 0,
            'activity_percentage' => null,
            'idle_percentage' => null,
            'pto_seconds' => 0,
            'holiday_seconds' => 0,
            'paid_day_off' => $preservePaidDayOff,
            'paid_break_seconds' => 0,
            'paid_time_not_tracked_seconds' => 0,
            'pending_idle_seconds' => 0,
            'justified_idle_seconds' => 0,
            'unjustified_idle_seconds' => 0,
            'justified_absence_seconds' => 0,
            'unjustified_absence_seconds' => $preservePaidDayOff ? 0 : $ordinarySeconds,
            'possible_overtime_seconds' => 0,
            'approved_overtime_seconds' => 0,
            'payable_seconds' => $payableSeconds,
            'difference_seconds' => -$ordinarySeconds,
            'status' => $preservePaidDayOff ? ($review->status ?: 'revisado_supervisor') : 'pendiente',
            'supervisor_comment' => $comment,
        ]);
        $review->save();
    }

    private function applyEmployeeSpecificCorrections(PayrollPeriod $period, Employee $employee): bool
    {
        if ($this->matchesName($employee, self::VICTOR_NAME)) {
            $this->applyTargetWorkdays($period, $employee, self::VICTOR_DAILY_TARGETS);

            return true;
        }

        if ($this->matchesName($employee, self::MARCO_NAME)) {
            $this->applyTargetWorkdays($period, $employee, self::MARCO_DAILY_TARGETS);

            foreach (self::MARCO_REPLACEMENT_OFF_DATES as $date) {
                $this->applyPaidOffDay($period, $employee, $date, self::MARCO_REPLACEMENT_OFF_COMMENT);
            }

            return true;
        }

        if ($this->matchesName($employee, self::MELANY_NAME)) {
            $this->applyMelanyAfterLastWorkDate($period, $employee);

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, array{tracked: int, paid_not_tracked?: int}>  $targets
     */
    private function applyTargetWorkdays(PayrollPeriod $period, Employee $employee, array $targets): void
    {
        foreach ($targets as $date => $target) {
            $review = $this->review($period, $employee, $date) ?? new DailyTimeReview([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'date' => $date,
            ]);

            $this->applyTargetWorkday(
                $review,
                (int) $target['tracked'],
                (int) ($target['paid_not_tracked'] ?? 0),
            );
        }
    }

    private function applyTargetWorkday(
        DailyTimeReview $review,
        int $trackedSeconds,
        int $paidNotTrackedSeconds,
    ): void {
        $ordinarySeconds = $this->hoursToSeconds(8);
        $overtimeSeconds = $this->hoursToSeconds(2);
        $requiredSeconds = $ordinarySeconds + $overtimeSeconds;
        $expectedHubstaffSeconds = max($requiredSeconds - $paidNotTrackedSeconds, 0);
        $creditedSeconds = min(
            $trackedSeconds + $paidNotTrackedSeconds + max((int) $review->justified_absence_seconds, 0),
            $requiredSeconds,
        );
        $remainingLostSeconds = max($requiredSeconds - $trackedSeconds - $paidNotTrackedSeconds - max((int) $review->justified_absence_seconds, 0), 0);
        $justifiedSeconds = min(max((int) $review->justified_absence_seconds, 0), max($requiredSeconds - $trackedSeconds - $paidNotTrackedSeconds, 0));

        $review->fill([
            'scheduled_work_day' => true,
            'expected_seconds' => $ordinarySeconds,
            'expected_ordinary_seconds' => $ordinarySeconds,
            'assigned_overtime_seconds' => $overtimeSeconds,
            'preassigned_overtime_seconds' => $overtimeSeconds,
            'additional_overtime_seconds' => 0,
            'assigned_overtime_fulfilled' => $creditedSeconds >= $requiredSeconds,
            'expected_paid_seconds' => $requiredSeconds,
            'expected_hubstaff_seconds' => $expectedHubstaffSeconds,
            'hubstaff_total_seconds' => $trackedSeconds,
            'hubstaff_regular_seconds' => $trackedSeconds,
            'hubstaff_idle_seconds' => 0,
            'activity_percentage' => null,
            'idle_percentage' => null,
            'pto_seconds' => 0,
            'holiday_seconds' => 0,
            'paid_day_off' => false,
            'paid_break_seconds' => 0,
            'paid_time_not_tracked_seconds' => $paidNotTrackedSeconds,
            'pending_idle_seconds' => 0,
            'justified_idle_seconds' => 0,
            'unjustified_idle_seconds' => 0,
            'justified_absence_seconds' => $justifiedSeconds,
            'unjustified_absence_seconds' => $remainingLostSeconds,
            'possible_overtime_seconds' => min($overtimeSeconds, max($trackedSeconds + $paidNotTrackedSeconds + $justifiedSeconds - $ordinarySeconds, 0)),
            'approved_overtime_seconds' => 0,
            'payable_seconds' => min($trackedSeconds + $paidNotTrackedSeconds + $justifiedSeconds, $requiredSeconds),
            'difference_seconds' => $trackedSeconds - $expectedHubstaffSeconds,
            'status' => $this->targetWorkdayStatus($review, $remainingLostSeconds),
        ]);
        $review->save();
    }

    private function targetWorkdayStatus(DailyTimeReview $review, int $remainingLostSeconds): string
    {
        if ($remainingLostSeconds <= 0) {
            return $review->status ?: 'revisado_supervisor';
        }

        if (
            $review->status === 'pendiente'
            || blank($review->supervisor_comment)
            || str_contains((string) $review->supervisor_comment, 'Ajuste puntual septiembre 2026')
        ) {
            return 'pendiente';
        }

        return $review->status ?: 'pendiente';
    }

    private function applyPaidOffDay(PayrollPeriod $period, Employee $employee, string $date, string $comment): void
    {
        HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->where('active', true)
            ->update(['active' => false]);

        $review = $this->review($period, $employee, $date) ?? new DailyTimeReview([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => $date,
        ]);
        $ordinarySeconds = $this->hoursToSeconds(8);

        $review->fill([
            'scheduled_work_day' => true,
            'expected_seconds' => $ordinarySeconds,
            'expected_ordinary_seconds' => $ordinarySeconds,
            'assigned_overtime_seconds' => 0,
            'preassigned_overtime_seconds' => 0,
            'additional_overtime_seconds' => 0,
            'assigned_overtime_fulfilled' => false,
            'expected_paid_seconds' => $ordinarySeconds,
            'expected_hubstaff_seconds' => $ordinarySeconds,
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
            'payable_seconds' => $ordinarySeconds,
            'difference_seconds' => -$ordinarySeconds,
            'status' => 'revisado_supervisor',
            'supervisor_comment' => $this->appendComment($review->supervisor_comment, $comment),
        ]);
        $review->save();
    }

    private function applyMelanyAfterLastWorkDate(PayrollPeriod $period, Employee $employee): void
    {
        HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>', self::MELANY_LAST_WORK_DATE)
            ->where('active', true)
            ->update(['active' => false]);

        foreach (CarbonPeriod::create(Carbon::parse(self::MELANY_LAST_WORK_DATE)->addDay(), $period->ends_at) as $date) {
            $dateString = $date->toDateString();
            $review = $this->review($period, $employee, $dateString) ?? new DailyTimeReview([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'date' => $dateString,
            ]);
            $expectation = $this->scheduleExpectationService->forDate($employee, Carbon::parse($dateString));
            $ordinarySeconds = (int) $expectation['expected_ordinary_seconds'];

            $review->fill([
                'scheduled_work_day' => (bool) $expectation['scheduled_work_day'],
                'expected_seconds' => $ordinarySeconds,
                'expected_ordinary_seconds' => $ordinarySeconds,
                'assigned_overtime_seconds' => 0,
                'preassigned_overtime_seconds' => 0,
                'additional_overtime_seconds' => 0,
                'assigned_overtime_fulfilled' => false,
                'expected_paid_seconds' => $ordinarySeconds,
                'expected_hubstaff_seconds' => $ordinarySeconds,
                'hubstaff_total_seconds' => 0,
                'hubstaff_regular_seconds' => 0,
                'hubstaff_idle_seconds' => 0,
                'activity_percentage' => null,
                'idle_percentage' => null,
                'pto_seconds' => 0,
                'holiday_seconds' => 0,
                'paid_day_off' => false,
                'paid_break_seconds' => 0,
                'paid_time_not_tracked_seconds' => 0,
                'pending_idle_seconds' => 0,
                'justified_idle_seconds' => 0,
                'unjustified_idle_seconds' => 0,
                'justified_absence_seconds' => 0,
                'unjustified_absence_seconds' => $ordinarySeconds,
                'possible_overtime_seconds' => 0,
                'approved_overtime_seconds' => 0,
                'payable_seconds' => 0,
                'difference_seconds' => -$ordinarySeconds,
                'status' => $review->status ?: 'revisado_supervisor',
            ]);
            $review->save();
        }
    }

    private function paidDayOffSeconds(Employee $employee, int $ordinarySeconds, bool $scheduledWorkDay, string $scheduleType): int
    {
        if ($ordinarySeconds > 0) {
            return $ordinarySeconds;
        }

        if (! $scheduledWorkDay && $scheduleType === 'rotativa') {
            return 0;
        }

        return $this->hoursToSeconds((float) ($employee->daily_hours ?: 8));
    }

    private function applyAshleyCorrection(PayrollPeriod $period, Employee $employee): void
    {
        $this->applyAshleyHoursOnly($employee);

        DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->delete();

        $this->payrollCalculationService->regenerateEmployeeDailyReviews($period, $employee->fresh());
        $this->applySaturdayNoWork($period, $employee->fresh());
    }

    private function applySaturdayDuplicateNineHourCleanup(PayrollPeriod $period, Employee $employee): int
    {
        return HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', self::SATURDAY_DATE)
            ->where('active', true)
            ->where('regular_seconds', 0)
            ->where('total_seconds', 32400)
            ->where(function ($query): void {
                $query->whereNull('project')
                    ->orWhere('project', '')
                    ->orWhere('project', 'Sin proyecto');
            })
            ->update(['active' => false]);
    }

    private function applyAshleyHoursOnly(Employee $employee): void
    {
        if ((float) $employee->daily_hours !== 8.0) {
            $employee->forceFill(['daily_hours' => 8])->save();
        }
    }

    private function applyAdministrativeFullPay(PayrollPeriod $period, Employee $employee): void
    {
        HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->where('active', true)
            ->update(['active' => false]);

        foreach (CarbonPeriod::create($period->starts_at, $period->ends_at) as $date) {
            $dateString = $date->toDateString();
            $review = $this->review($period, $employee, $dateString) ?? new DailyTimeReview([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'date' => $dateString,
            ]);
            $expectation = $this->scheduleExpectationService->forDate($employee, Carbon::parse($dateString));
            $ordinarySeconds = (int) $expectation['expected_ordinary_seconds'];

            if ($ordinarySeconds <= 0) {
                continue;
            }

            $review->fill([
                'scheduled_work_day' => true,
                'expected_seconds' => $ordinarySeconds,
                'expected_ordinary_seconds' => $ordinarySeconds,
                'assigned_overtime_seconds' => 0,
                'preassigned_overtime_seconds' => 0,
                'additional_overtime_seconds' => 0,
                'assigned_overtime_fulfilled' => false,
                'expected_paid_seconds' => $ordinarySeconds,
                'expected_hubstaff_seconds' => $ordinarySeconds,
                'hubstaff_total_seconds' => 0,
                'hubstaff_regular_seconds' => 0,
                'hubstaff_idle_seconds' => 0,
                'activity_percentage' => null,
                'idle_percentage' => null,
                'pto_seconds' => 0,
                'holiday_seconds' => 0,
                'paid_day_off' => false,
                'paid_break_seconds' => 0,
                'paid_time_not_tracked_seconds' => 0,
                'pending_idle_seconds' => 0,
                'justified_idle_seconds' => 0,
                'unjustified_idle_seconds' => 0,
                'justified_absence_seconds' => $ordinarySeconds,
                'unjustified_absence_seconds' => 0,
                'possible_overtime_seconds' => 0,
                'approved_overtime_seconds' => 0,
                'payable_seconds' => $ordinarySeconds,
                'difference_seconds' => -$ordinarySeconds,
                'status' => 'revisado_supervisor',
                'supervisor_comment' => self::ADMIN_COMMENT,
            ]);
            $review->save();
        }
    }

    /**
     * @return Collection<int, Employee>
     */
    private function saturdayNoWorkEmployees(): Collection
    {
        $namedEmployees = collect(self::SATURDAY_NO_WORK_NAMES)
            ->map(fn (string $name): ?Employee => $this->employeeByName($name))
            ->filter();

        $palmettoEmployees = Employee::query()
            ->whereHas('campaign', fn ($query) => $query->whereRaw('lower(name) = ?', ['palmetto']))
            ->where(function ($query): void {
                $query->where('active', true)
                    ->orWhereHas('hubstaffTimeEntries', fn ($entryQuery) => $entryQuery
                        ->whereDate('date', self::SATURDAY_DATE)
                        ->where('active', true));
            })
            ->get();

        return $namedEmployees
            ->merge($palmettoEmployees)
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, Employee>
     */
    private function adminEmployees(): Collection
    {
        return collect(self::ADMIN_NAMES)
            ->map(fn (string $name): ?Employee => $this->employeeByName($name))
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, Employee>
     */
    private function saturdayDuplicateNineHourEmployees(): Collection
    {
        return collect(self::SATURDAY_DUPLICATE_NINE_HOUR_NAMES)
            ->map(fn (string $name): ?Employee => $this->employeeByName($name))
            ->filter()
            ->unique('id')
            ->values();
    }

    private function employeeByName(string $name): ?Employee
    {
        return Employee::query()
            ->get()
            ->first(fn (Employee $employee): bool => $this->matchesName($employee, $name));
    }

    private function isSaturdayNoWorkEmployee(Employee $employee): bool
    {
        return $this->matchesAnyName($employee, self::SATURDAY_NO_WORK_NAMES)
            || $this->isPalmettoEmployee($employee);
    }

    private function isPalmettoEmployee(Employee $employee): bool
    {
        return strtolower((string) $employee->campaign?->name) === 'palmetto'
            || strtolower((string) $employee->campaign()->value('name')) === 'palmetto';
    }

    private function isAdminEmployee(Employee $employee): bool
    {
        return $this->matchesAnyName($employee, self::ADMIN_NAMES);
    }

    private function isSaturdayDuplicateNineHourEmployee(Employee $employee): bool
    {
        return $this->matchesAnyName($employee, self::SATURDAY_DUPLICATE_NINE_HOUR_NAMES);
    }

    /**
     * @param  array<int, string>  $names
     */
    private function matchesAnyName(Employee $employee, array $names): bool
    {
        return collect($names)->contains(fn (string $name): bool => $this->matchesName($employee, $name));
    }

    private function matchesName(Employee $employee, string $name): bool
    {
        $tokens = collect(explode(' ', $this->normalizeName($name)))
            ->filter()
            ->values();

        if ($tokens->isEmpty()) {
            return false;
        }

        $employeeName = $this->normalizeName($employee->name.' '.$employee->hubstaff_name);

        return $tokens->every(fn (string $token): bool => str_contains($employeeName, $token));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function outsideSaturdayEntries(PayrollPeriod $period): Collection
    {
        $targetIds = $this->saturdayNoWorkEmployees()->pluck('id')->all();

        return HubstaffTimeEntry::query()
            ->with('employee.campaign')
            ->where('payroll_period_id', $period->id)
            ->whereDate('date', self::SATURDAY_DATE)
            ->where('active', true)
            ->whereNotNull('employee_id')
            ->whereNotIn('employee_id', $targetIds)
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $entries): array => [
                'employee_id' => $entries->first()->employee->id,
                'employee' => $entries->first()->employee->name,
                'campaign' => $entries->first()->employee->campaign?->name,
                'seconds' => (int) $entries->sum('total_seconds'),
            ])
            ->sortBy('employee')
            ->values();
    }

    private function review(PayrollPeriod $period, Employee $employee, string $date): ?DailyTimeReview
    {
        return DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();
    }

    private function reviewCount(PayrollPeriod $period, Employee $employee): int
    {
        return DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->count();
    }

    private function reviewSummary(?DailyTimeReview $review): string
    {
        if (! $review) {
            return 'Sin revisión';
        }

        return sprintf(
            'Hubstaff %s, pagable %s, OFF %s, estado %s',
            $this->hours((int) $review->hubstaff_total_seconds),
            $this->hours((int) $review->payable_seconds),
            $review->paid_day_off ? 'sí' : 'no',
            $review->status,
        );
    }

    private function saturdayEntriesSummary(PayrollPeriod $period, Employee $employee): string
    {
        $entries = HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', self::SATURDAY_DATE)
            ->where('active', true)
            ->get();
        $duplicateSeconds = (int) $entries
            ->filter(fn (HubstaffTimeEntry $entry): bool => (int) $entry->regular_seconds === 0
                && (int) $entry->total_seconds === 32400
                && in_array((string) $entry->project, ['', 'Sin proyecto'], true))
            ->sum('total_seconds');

        return 'Activo '.$this->hours((int) $entries->sum('total_seconds')).', bloque Sin proyecto '.$this->hours($duplicateSeconds);
    }

    private function hours(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%d:%02d', $hours, $minutes);
    }

    private function hoursToSeconds(float $hours): int
    {
        return max((int) round($hours * 3600), 0);
    }

    private function appendComment(?string $current, string $comment): string
    {
        $current = trim((string) $current);

        if ($current === '') {
            return $comment;
        }

        if (str_contains($current, $comment)) {
            return $current;
        }

        return $current."\n".$comment;
    }

    private function normalizeName(string $name): string
    {
        return (string) Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }
}
