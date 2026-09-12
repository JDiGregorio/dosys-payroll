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

    private const ASHLEY_NAME = 'Ashley Escobar';

    private const ADMIN_NAMES = [
        'Jonathan Garcia',
        'Orely Ramirez',
    ];

    private const SATURDAY_COMMENT = 'Ajuste puntual septiembre 2026: sábado 5 no trabajado, tiempo importado fuera del cálculo.';

    private const ADMIN_COMMENT = 'Ajuste administrativo: empleado sin tracker, quincena pagada completa.';

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
            $rows[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->name,
                'action' => 'Remover tiempo sábado 5',
                'before' => $this->reviewSummary($this->review($period, $employee, self::SATURDAY_DATE)),
                'after' => 'Sin horas activas, sin OFF, no pagado salvo revisión posterior.',
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

            foreach ($this->saturdayNoWorkEmployees() as $employee) {
                $this->applySaturdayNoWork($period, $employee);
                $affected->push($employee->id);
            }

            if ($ashley = $this->employeeByName(self::ASHLEY_NAME)) {
                $this->applyAshleyCorrection($period, $ashley);
                $affected->push($ashley->id);
            }

            foreach ($this->adminEmployees() as $employee) {
                $this->applyAdministrativeFullPay($period, $employee);
                $affected->push($employee->id);
            }

            Employee::query()
                ->whereIn('id', $affected->unique()->values()->all())
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

        $this->adminEmployees()
            ->each(fn (Employee $employee): bool => $this->applyForEmployee($period, $employee));

        if ($ashley = $this->employeeByName(self::ASHLEY_NAME)) {
            $this->applyAshleyHoursOnly($ashley);
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

        if ($this->isAdminEmployee($employee)) {
            $this->applyAdministrativeFullPay($period, $employee);
            $applied = true;
        }

        if ($this->matchesName($employee, self::ASHLEY_NAME)) {
            $this->applyAshleyHoursOnly($employee);
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
            'status' => 'pendiente',
            'supervisor_comment' => self::SATURDAY_COMMENT,
        ]);
        $review->save();
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

    private function employeeByName(string $name): ?Employee
    {
        return Employee::query()
            ->get()
            ->first(fn (Employee $employee): bool => $this->matchesName($employee, $name));
    }

    private function isSaturdayNoWorkEmployee(Employee $employee): bool
    {
        return $this->matchesAnyName($employee, self::SATURDAY_NO_WORK_NAMES)
            || strtolower((string) $employee->campaign?->name) === 'palmetto'
            || strtolower((string) $employee->campaign()->value('name')) === 'palmetto';
    }

    private function isAdminEmployee(Employee $employee): bool
    {
        return $this->matchesAnyName($employee, self::ADMIN_NAMES);
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

    private function hours(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%d:%02d', $hours, $minutes);
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
