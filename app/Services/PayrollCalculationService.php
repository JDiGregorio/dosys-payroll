<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PaidTimeProject;
use App\Models\PayrollOvertimeAdjustment;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\TrackabiDailyActivity;
use App\Services\Trackabi\TrackabiActivityCoverageService;
use App\Services\Trackabi\TrackabiPaidActivityService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayrollCalculationService
{
    public function __construct(
        private readonly BonusApplicationService $bonusService,
        private readonly DeductionApplicationService $deductionService,
        private readonly ScheduleExpectationService $scheduleExpectationService,
        private readonly TrackabiPaidActivityService $trackabiPaidActivityService,
    ) {}

    public function generateDailyReviews(PayrollPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $entriesByEmployeeDate = HubstaffTimeEntry::query()
                ->with('employee')
                ->where('payroll_period_id', $period->id)
                ->where('active', true)
                ->whereNotNull('employee_id')
                ->get()
                ->groupBy(fn (HubstaffTimeEntry $entry) => $entry->employee_id.'|'.$entry->date->toDateString());

            $employees = Employee::query()
                ->with([
                    'scheduleType',
                    'workScheduleTemplate.days',
                    'scheduleAssignments.template.days',
                ])
                ->where('active', true)
                ->get();

            foreach ($employees as $employee) {
                $this->syncEmployeeDailyReviews($period, $employee, $entriesByEmployeeDate);
            }

            $this->generatePayrollDeductions($period);
        });
    }

    public function regenerateEmployeeDailyReviews(
        PayrollPeriod $period,
        Employee $employee,
    ): void {
        DB::transaction(function () use ($period, $employee): void {
            $entriesByEmployeeDate = HubstaffTimeEntry::query()
                ->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->where('active', true)
                ->get()
                ->groupBy(fn (HubstaffTimeEntry $entry) => $entry->employee_id.'|'.$entry->date->toDateString());

            $this->syncEmployeeDailyReviews($period, $employee, $entriesByEmployeeDate);
            $this->recalculateEmployeePayrollResult($period, $employee);
        });
    }

    public function recalculateDailyReview(DailyTimeReview $review): void
    {
        $employee = $review->relationLoaded('employee')
            ? $review->employee
            : $review->employee()->first();
        $period = $review->relationLoaded('payrollPeriod')
            ? $review->payrollPeriod
            : $review->payrollPeriod()->first();

        if ($employee && $period) {
            $this->redistributeAssignedOvertime($period, $employee);

            return;
        }

        $this->calculateDailyReview($review, $employee);
    }

    private function calculateDailyReview(DailyTimeReview $review, ?Employee $employee = null): void
    {
        if ($employee) {
            $this->applyScheduleExpectation($review, $employee);
        }

        if ($review->paid_day_off) {
            $review->justified_absence_seconds = 0;
            $review->unjustified_absence_seconds = 0;
            $review->payable_seconds = $this->paidDayOffSeconds($review) + $this->offWorkedUnscheduledSeconds($review);
        } elseif ($this->isPayableUnscheduledWork($review)) {
            $review->justified_absence_seconds = 0;
            $review->unjustified_absence_seconds = 0;
            $review->payable_seconds = max((int) $review->hubstaff_total_seconds, 0);
        } else {
            $review->payable_seconds = $this->regularPayableSeconds($review)
                + $this->paidAssignedOvertimeSeconds($review);
        }

        $review->difference_seconds = (int) $review->hubstaff_total_seconds
            - (int) $review->expected_hubstaff_seconds;
        if ($review->verified_tracked_seconds !== null) {
            $review->difference_seconds = (int) $review->verified_tracked_seconds
                - (int) $review->expected_hubstaff_seconds;
        }
        if ($employee?->paid_without_tracking) {
            $review->difference_seconds = 0;
        }
        $review->possible_overtime_seconds = $this->paidAssignedOvertimeSeconds($review);

        $review->save();
    }

    public function recalculateEmployeePayrollResult(PayrollPeriod $period, Employee $employee): void
    {
        if ($employee->active === false) {
            PayrollResult::query()
                ->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->delete();

            return;
        }

        $reviews = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$period->starts_at, $period->ends_at])
            ->with('overtimeRateType')
            ->get();

        if ($reviews->isEmpty()) {
            return;
        }

        $regularPayableSeconds = (int) $reviews->sum(fn (DailyTimeReview $review): int => $this->regularPayableSeconds($review));
        $preassignedOvertimeSeconds = (int) $reviews->sum(fn (DailyTimeReview $review): int => $this->paidAssignedOvertimeSeconds($review));
        $payableSeconds = $regularPayableSeconds + $preassignedOvertimeSeconds
            + (int) $reviews->sum(fn (DailyTimeReview $review): int => $this->offWorkedUnscheduledSeconds($review));
        $hourlyRate = $this->employeeHourlyRate($employee);
        $monthlySalary = $this->employeeMonthlySalary($employee, $hourlyRate);
        $biweeklySalary = $this->employeeSemiMonthlySalary($employee, $monthlySalary);
        $dailyRate = $this->employeeDailyRate($employee, $monthlySalary);
        $fallbackOvertimeRate = $this->employeeOvertimeRate($employee, $hourlyRate);
        $salaryCalculationMethod = $this->effectiveSalaryCalculationMethod($period, $employee);
        $offWorkedExtraAmount = round((float) $reviews->sum(function (DailyTimeReview $review) use ($dailyRate, $salaryCalculationMethod): float {
            $verified = $this->validOffWorkedSeconds($review);
            if ($verified === 0) {
                return 0;
            }

            $baseAlreadyPaysDay = in_array($salaryCalculationMethod, [
                'semi_monthly_fixed_with_deductions', 'monthly_calendar_prorated',
            ], true);
            $multiplier = ! $review->scheduled_work_day && $this->isRotatingSchedule($review->employee)
                && ! $baseAlreadyPaysDay ? 2 : 1;

            return $dailyRate * $multiplier;
        }), 2);
        $preassignedOvertimeAmount = ($preassignedOvertimeSeconds / 3600) * $fallbackOvertimeRate;

        [$manualOvertimeSeconds, $manualOvertimeAmount] = $this->manualOvertime($period, $employee);
        $overtimeAmount = round($preassignedOvertimeAmount + $manualOvertimeAmount, 2);

        $qaBonus = $this->bonusService->amountByType($period, $employee, ['qa']);
        $productivityBonus = $this->bonusService->amountByType($period, $employee, ['productivity']);
        $timeManagementBonus = $this->bonusService->amountByType($period, $employee, ['time_management']);
        $referredBonus = $this->bonusService->amountByType($period, $employee, ['referred']);
        $tierAdjustmentBonus = $this->bonusService->amountByType($period, $employee, ['adjustment', 'tier_adjustment']);
        $vacationBonus = $this->bonusService->amountByType($period, $employee, ['vacation']);
        $extraBonuses = $this->bonusService->amountByType($period, $employee, ['manual', 'other', 'client_extra']);
        $internetSubsidy = ($employee->location === 'remote' ? (float) $employee->internet_subsidy_amount : 0.0)
            + $this->bonusService->amountByType($period, $employee, ['internet_subsidy']);
        $payrollCompensation = 0.0;
        $extrasTotal = round($overtimeAmount + $internetSubsidy + $qaBonus + $productivityBonus + $timeManagementBonus + $extraBonuses + $referredBonus + $tierAdjustmentBonus + $vacationBonus + $payrollCompensation + $offWorkedExtraAmount, 2);
        $workedDays = $this->workedDays($reviews);
        $scheduledDays = (float) $reviews->where('scheduled_work_day', true)->count();
        $extraCalendarDays = $this->extraStandardBiweeklyCalendarDays($period);
        $fixedSalaryBase = $biweeklySalary + ($extraCalendarDays * $dailyRate);
        $regularLostSeconds = (int) $reviews->sum(
            fn (DailyTimeReview $review): int => $this->regularUnjustifiedSeconds($review),
        );
        $idleLostSeconds = 0;
        $overtimeLostSeconds = 0;
        $directFixedSalary = $salaryCalculationMethod === 'semi_monthly_fixed_with_deductions'
            && app(TrackabiActivityCoverageService::class)->applies($period);
        $regularLostAmount = (float) $reviews->sum(function (DailyTimeReview $review) use ($directFixedSalary, $dailyRate, $hourlyRate): float {
            $lostSeconds = $this->regularUnjustifiedSeconds($review);
            $expectedSeconds = $review->hasUntrackedDirectAbsence()
                ? $review->untrackedDayReviewSeconds()
                : max((int) $review->expected_ordinary_seconds, 0);

            if (! $directFixedSalary || $expectedSeconds === 0) {
                return ($lostSeconds / 3600) * $hourlyRate;
            }

            return (min($lostSeconds, $expectedSeconds) / $expectedSeconds) * $dailyRate
                + (max($lostSeconds - $expectedSeconds, 0) / 3600) * $hourlyRate;
        });
        $overtimeLostAmount = 0.0;
        $absenceDeduction = round($regularLostAmount, 2);
        $lostTimeSeconds = $regularLostSeconds + $idleLostSeconds + $overtimeLostSeconds;
        $lostTimeAmount = round($absenceDeduction + $overtimeLostAmount, 2);
        $expectedOrdinarySeconds = (int) $reviews->sum('expected_ordinary_seconds');
        $workedSalary = round(match ($salaryCalculationMethod) {
            'semi_monthly_fixed_with_deductions' => max(
                $fixedSalaryBase - $absenceDeduction,
                0,
            ),
            'monthly_calendar_prorated' => $workedDays * $dailyRate,
            'scheduled_shift_prorated' => $expectedOrdinarySeconds > 0
                ? $biweeklySalary * min($regularPayableSeconds / $expectedOrdinarySeconds, 1)
                : 0,
            default => ($regularPayableSeconds / 3600) * $hourlyRate,
        }, 2);
        $grossAmount = round($workedSalary + $extrasTotal, 2);

        $deductions = $this->deductionService->approvedForEmployee($period, $employee);
        $deductionAmountByCode = fn (string $code): float => (float) $deductions
            ->filter(fn ($deduction): bool => $deduction->deductionType?->code === $code)
            ->sum('amount');
        $privateInsurance = $deductionAmountByCode('private_insurance');
        $ihss = $deductionAmountByCode('ihss');
        $isr = $deductionAmountByCode('isr');
        $rap = $deductionAmountByCode('rap');
        $vouchers = $deductionAmountByCode('vouchers');
        $tierAdjustmentDeduction = (float) $deductions
            ->filter(fn ($deduction): bool => $deduction->deductionType?->code === 'additional'
                && $deduction->additional_type === 'adjustment')
            ->sum('amount');
        $otherDeductions = (float) $deductions
            ->filter(fn ($deduction): bool => $deduction->deductionType?->code === 'additional'
                && ($deduction->additional_type === 'other' || blank($deduction->additional_type)))
            ->sum('amount');
        $totalDeductions = round((float) $deductions->sum('amount'), 2);
        $netAmount = round($grossAmount - $totalDeductions, 2);

        $result = PayrollResult::query()->firstOrNew([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);
        $result->fill([
            'salary_calculation_method' => $salaryCalculationMethod,
            'monthly_salary' => round($monthlySalary, 2),
            'biweekly_salary_amount' => round($biweeklySalary, 2),
            'daily_rate' => round($dailyRate, 4),
            'hourly_rate' => round($hourlyRate, 4),
            'overtime_hourly_rate' => round($fallbackOvertimeRate, 4),
            'worked_days' => round($workedDays, 2),
            'scheduled_days' => round($scheduledDays, 2),
            'worked_hours' => round($payableSeconds / 3600, 2),
            'expected_seconds' => $expectedOrdinarySeconds,
            'expected_hubstaff_seconds' => (int) $reviews->sum('expected_hubstaff_seconds'),
            'expected_paid_seconds' => (int) $reviews->sum('expected_paid_seconds'),
            'hubstaff_total_seconds' => (int) $reviews->sum('hubstaff_total_seconds'),
            'justified_idle_seconds' => (int) $reviews->sum('justified_idle_seconds'),
            'unjustified_idle_seconds' => (int) $reviews->sum('unjustified_idle_seconds'),
            'justified_absence_seconds' => (int) $reviews->sum('justified_absence_seconds'),
            'unjustified_absence_seconds' => (int) $reviews->sum('unjustified_absence_seconds'),
            'regular_lost_seconds' => $regularLostSeconds,
            'overtime_lost_seconds' => $overtimeLostSeconds,
            'lost_time_seconds' => $lostTimeSeconds,
            'payable_seconds' => $payableSeconds,
            'worked_salary_amount' => $workedSalary,
            'regular_lost_amount' => round($regularLostAmount, 2),
            'overtime_lost_amount' => round($overtimeLostAmount, 2),
            'lost_time_amount' => $lostTimeAmount,
            'base_salary_amount' => in_array($salaryCalculationMethod, [
                'semi_monthly_fixed_with_deductions',
                'scheduled_shift_prorated',
            ], true) ? round($fixedSalaryBase, 2) : $workedSalary,
            'absence_deduction' => $absenceDeduction,
            'idle_deduction' => 0,
            'extra_bonuses_amount' => round($extraBonuses, 2),
            'off_worked_extra_amount' => $offWorkedExtraAmount,
            'referred_bonus_amount' => round($referredBonus, 2),
            'adjustment_bonus_amount' => round($tierAdjustmentBonus, 2),
            'tier_adjustment_bonus_amount' => round($tierAdjustmentBonus, 2),
            'vacation_bonus_amount' => round($vacationBonus, 2),
            'bonuses_amount' => round($extraBonuses + $referredBonus + $tierAdjustmentBonus + $vacationBonus + $qaBonus + $productivityBonus + $timeManagementBonus + $internetSubsidy + $offWorkedExtraAmount, 2),
            'overtime_seconds' => $preassignedOvertimeSeconds + $manualOvertimeSeconds,
            'preassigned_overtime_seconds' => $preassignedOvertimeSeconds,
            'additional_overtime_seconds' => $manualOvertimeSeconds,
            'overtime_amount' => $overtimeAmount,
            'internet_subsidy_amount' => round($internetSubsidy, 2),
            'qa_bonus_amount' => round($qaBonus, 2),
            'productivity_bonus_amount' => round($productivityBonus, 2),
            'time_management_bonus_amount' => round($timeManagementBonus, 2),
            'payroll_compensation_amount' => round($payrollCompensation, 2),
            'extras_total_amount' => $extrasTotal,
            'gross_amount' => $grossAmount,
            'private_insurance_amount' => round($privateInsurance, 2),
            'ihss_amount' => round($ihss, 2),
            'isr_amount' => round($isr, 2),
            'rap_amount' => round($rap, 2),
            'vouchers_amount' => round($vouchers, 2),
            'tier_adjustment_deduction_amount' => round($tierAdjustmentDeduction, 2),
            'other_deductions_amount' => round($otherDeductions, 2),
            'total_deductions_amount' => $totalDeductions,
            'net_amount' => $netAmount,
            'status' => $result->exists
                ? $result->status
                : ($period->status === 'aprobado' ? 'aprobado' : 'borrador'),
        ]);
        $result->save();
    }

    public function recalculatePayrollResults(PayrollPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $this->generatePayrollDeductions($period);

            PayrollResult::query()
                ->where('payroll_period_id', $period->id)
                ->whereHas('employee', fn ($query) => $query->where('employees.active', false))
                ->delete();

            Employee::query()
                ->with([
                    'scheduleType',
                    'workScheduleTemplate.days',
                    'scheduleAssignments.template.days',
                    'tierLevel',
                ])
                ->where('employees.active', true)
                ->whereHas('dailyTimeReviews', fn ($query) => $query
                    ->where('payroll_period_id', $period->id)
                    ->whereBetween('date', [$period->starts_at, $period->ends_at]))
                ->each(function (Employee $employee) use ($period): void {
                    $this->redistributeAssignedOvertime($period, $employee);
                    $this->recalculateEmployeePayrollResult($period, $employee);
                });
        });
    }

    public function recalculatePeriodPreservingManual(PayrollPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            app(JulySecondHalfPayrollCorrectionsService::class)->applyForPeriod($period);
            app(AugustSecondHalfPayrollCorrectionsService::class)->applyForPeriod($period);
            app(SeptemberFirstHalfPayrollCorrectionsService::class)->applyForPeriod($period);

            $manualReviewState = $this->manualReviewState($period);
            $bonusState = $period->payrollBonuses()->orderBy('id')->get()->map->getAttributes()->all();
            $deductionState = $this->protectedPayrollDeductionState($period);
            $hubstaffState = $period->hubstaffTimeEntries()->orderBy('id')->get()->map->getAttributes()->all();

            $this->generateDailyReviews($period);
            $this->recalculatePayrollResults($period);

            $currentManualReviewState = $this->manualReviewState($period);

            if ($manualReviewState !== array_intersect_key($currentManualReviewState, $manualReviewState)) {
                throw new \RuntimeException(
                    'El recálculo intentó modificar justificaciones, comentarios, aprobaciones o estados manuales.',
                );
            }

            if ($bonusState !== $period->payrollBonuses()->orderBy('id')->get()->map->getAttributes()->all()) {
                throw new \RuntimeException('El recálculo intentó modificar bonos existentes.');
            }

            if ($deductionState !== array_intersect_key($this->protectedPayrollDeductionState($period), $deductionState)) {
                throw new \RuntimeException('El recálculo intentó modificar deducciones existentes.');
            }

            if ($hubstaffState !== $period->hubstaffTimeEntries()->orderBy('id')->get()->map->getAttributes()->all()) {
                throw new \RuntimeException('El recálculo intentó modificar registros importados de Hubstaff.');
            }

            if ($period->status !== 'cerrado') {
                $period->update(['status' => 'en_revision']);
            }
        });
    }

    public function recalculateEmployeePreservingManual(PayrollPeriod $period, Employee $employee): void
    {
        DB::transaction(function () use ($period, $employee): void {
            app(JulySecondHalfPayrollCorrectionsService::class)->applyForEmployee($period, $employee);
            app(AugustSecondHalfPayrollCorrectionsService::class)->applyForEmployee($period, $employee);
            app(SeptemberFirstHalfPayrollCorrectionsService::class)->applyForEmployee($period, $employee);

            $manualReviewState = $this->manualReviewState($period, $employee);
            $entriesByEmployeeDate = HubstaffTimeEntry::query()
                ->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->where('active', true)
                ->get()
                ->groupBy(fn (HubstaffTimeEntry $entry) => $entry->employee_id.'|'.$entry->date->toDateString());

            $this->syncEmployeeDailyReviews($period, $employee, $entriesByEmployeeDate);
            $this->recalculateEmployeePayrollResult($period, $employee);

            $currentManualReviewState = $this->manualReviewState($period, $employee);

            if ($manualReviewState !== array_intersect_key($currentManualReviewState, $manualReviewState)) {
                throw new \RuntimeException('El recálculo del empleado intentó modificar información manual.');
            }
        });
    }

    public function generatePayrollResults(PayrollPeriod $period): void
    {
        $this->recalculatePayrollResults($period);
    }

    public function generatePayrollDeductions(PayrollPeriod $period): void
    {
        $this->deductionService->generateForPeriod($period);
    }

    private function paidBreakSeconds(Collection $entries): int
    {
        $rules = PaidTimeProject::query()
            ->where('active', true)
            ->where('is_paid', true)
            ->whereIn('category', ['break', 'lunch'])
            ->get();

        return (int) $entries->filter(function (HubstaffTimeEntry $entry) use ($rules): bool {
            $project = mb_strtolower((string) $entry->project);

            return $rules->contains(function (PaidTimeProject $rule) use ($project): bool {
                $name = mb_strtolower($rule->name);

                return $rule->match_type === 'exact'
                    ? $project === $name
                    : str_contains($project, $name);
            });
        })->sum('total_seconds');
    }

    private function weightedPercentage(Collection $entries, string $field): ?float
    {
        $weightedEntries = $entries->filter(fn (HubstaffTimeEntry $entry): bool => $entry->{$field} !== null);

        if ($weightedEntries->isEmpty()) {
            return null;
        }

        $totalSeconds = (int) $weightedEntries->sum('total_seconds');

        if ($totalSeconds <= 0) {
            return round((float) $weightedEntries->avg($field), 2);
        }

        $weightedTotal = $weightedEntries->sum(fn (HubstaffTimeEntry $entry): float => (float) $entry->{$field} * (int) $entry->total_seconds);

        return round($weightedTotal / $totalSeconds, 2);
    }

    private function employeeHourlyRate(Employee $employee): float
    {
        $configuredRate = (float) $employee->hourly_rate
            ?: (float) $employee->tierLevel?->hourly_rate;

        if ($configuredRate > 0) {
            return $configuredRate;
        }

        $monthlySalary = (float) $employee->monthly_salary
            ?: (float) $employee->tierLevel?->monthly_salary;
        $monthlyHours = $this->fallbackDailyHours($employee)
            * max((float) $employee->calendar_days, 30);

        return $monthlySalary > 0 && $monthlyHours > 0
            ? $monthlySalary / $monthlyHours
            : 0.0;
    }

    private function employeeMonthlySalary(Employee $employee, float $hourlyRate): float
    {
        return (float) $employee->monthly_salary
            ?: (float) $employee->tierLevel?->monthly_salary
            ?: $this->fallbackDailyHours($employee) * $hourlyRate * max((float) $employee->calendar_days, 30);
    }

    private function employeeSemiMonthlySalary(Employee $employee, float $monthlySalary): float
    {
        return (float) $employee->semi_monthly_salary
            ?: $monthlySalary / 2;
    }

    private function employeeDailyRate(Employee $employee, float $monthlySalary): float
    {
        return (float) $employee->daily_rate
            ?: $monthlySalary / max((float) $employee->calendar_days, 30);
    }

    private function employeeOvertimeRate(Employee $employee, float $hourlyRate): float
    {
        return (float) $employee->overtime_hourly_rate
            ?: $hourlyRate * 1.25;
    }

    private function effectiveSalaryCalculationMethod(PayrollPeriod $period, Employee $employee): string
    {
        if ($employee->paid_without_tracking) {
            return 'semi_monthly_fixed_with_deductions';
        }
        $configuredMethod = $employee->salary_calculation_method ?: 'hourly_actual_hours';

        if (
            $configuredMethod === 'hourly_actual_hours'
            && $this->isStandardBiweeklyPayrollPeriod($period)
            && $this->hasConfiguredFixedSalary($employee)
        ) {
            return 'semi_monthly_fixed_with_deductions';
        }

        return $configuredMethod;
    }

    private function isStandardBiweeklyPayrollPeriod(PayrollPeriod $period): bool
    {
        if (! $period->starts_at || ! $period->ends_at) {
            return false;
        }

        $startsAt = $period->starts_at->copy()->startOfDay();
        $endsAt = $period->ends_at->copy()->startOfDay();

        if ($endsAt->lessThan($startsAt)) {
            return false;
        }

        $startDay = (int) $startsAt->day;
        $endDay = (int) $endsAt->day;

        return ($startDay === 11 && $endDay === 25)
            || ($startDay === 26 && $endDay === 10);
    }

    private function extraStandardBiweeklyCalendarDays(PayrollPeriod $period): int
    {
        if (! $this->isStandardBiweeklyPayrollPeriod($period)) {
            return 0;
        }

        return max($this->calendarDaysInPeriod($period) - 15, 0);
    }

    private function calendarDaysInPeriod(PayrollPeriod $period): int
    {
        if (! $period->starts_at || ! $period->ends_at) {
            return 0;
        }

        return (int) $period->starts_at
            ->copy()
            ->startOfDay()
            ->diffInDays($period->ends_at->copy()->startOfDay()) + 1;
    }

    private function hasConfiguredFixedSalary(Employee $employee): bool
    {
        return (float) $employee->semi_monthly_salary > 0
            || (float) $employee->monthly_salary > 0
            || (float) $employee->tierLevel?->semi_monthly_salary > 0
            || (float) $employee->tierLevel?->monthly_salary > 0;
    }

    private function fallbackDailyHours(Employee $employee): float
    {
        return (float) $employee->daily_hours
            ?: (((float) $employee->ordinary_weekly_hours ?: (float) $employee->weekly_hours) / 5);
    }

    private function requiredSeconds(DailyTimeReview $review): int
    {
        return (int) $review->expected_paid_seconds
            ?: (int) $review->expected_ordinary_seconds + (int) $review->preassigned_overtime_seconds;
    }

    private function regularPayableSeconds(DailyTimeReview $review): int
    {
        if ($review->paid_day_off) {
            return $this->paidDayOffSeconds($review);
        }

        if ($this->isFullyJustifiedAbsence($review)) {
            return $this->paidDayOffSeconds($review);
        }

        if ($this->isPayableUnscheduledWork($review)) {
            return max((int) $review->hubstaff_total_seconds, 0);
        }

        $creditedSeconds = $this->creditedRequiredSeconds($review);

        if (! $review->assigned_overtime_fulfilled) {
            return min($creditedSeconds, (int) $review->expected_ordinary_seconds);
        }

        return min(
            max($creditedSeconds - $this->paidAssignedOvertimeSeconds($review), 0),
            (int) $review->expected_ordinary_seconds,
        );
    }

    private function paidAssignedOvertimeSeconds(DailyTimeReview $review): int
    {
        if ($review->paid_day_off || $this->isFullyJustifiedAbsence($review)) {
            return 0;
        }

        $creditedSeconds = $this->creditedRequiredSeconds($review);
        $payableOvertimeSeconds = $review->assigned_overtime_fulfilled
            ? $creditedSeconds
            : max($creditedSeconds - (int) $review->expected_ordinary_seconds, 0);

        return min(
            (int) $review->preassigned_overtime_seconds,
            $payableOvertimeSeconds,
        );
    }

    private function paidDayOffSeconds(DailyTimeReview $review): int
    {
        $expectedOrdinarySeconds = (int) $review->expected_ordinary_seconds;

        if ($expectedOrdinarySeconds > 0) {
            return $expectedOrdinarySeconds;
        }

        $employee = $review->relationLoaded('employee')
            ? $review->employee
            : $review->employee()->first();

        if (! $employee) {
            return 0;
        }

        if (! $review->scheduled_work_day && $this->isRotatingSchedule($employee)) {
            return 0;
        }

        return $this->hoursToSeconds($this->fallbackDailyHours($employee));
    }

    private function validOffWorkedSeconds(DailyTimeReview $review): int
    {
        $verified = max((int) $review->off_worked_verified_seconds, 0);
        $required = $review->offWorkedRequiredSeconds();

        return $review->off_worked && $review->paid_day_off && $review->payrollPeriod
            && app(TrackabiActivityCoverageService::class)->applies($review->payrollPeriod)
            && (int) ($review->trackabiActivity?->measured_activity_seconds ?? 0) > 0
            && $required > 0 && $verified > 0 && $verified <= $required
            && $verified <= (int) $review->trackabiActivity->measured_activity_seconds
            ? $verified : 0;
    }

    private function offWorkedUnscheduledSeconds(DailyTimeReview $review): int
    {
        return $review->employee && ! $review->scheduled_work_day && $this->isRotatingSchedule($review->employee)
            && $this->validOffWorkedSeconds($review) > 0
            ? $review->offWorkedRequiredSeconds() : 0;
    }

    private function hoursToSeconds(float $hours): int
    {
        return max((int) round($hours * 3600), 0);
    }

    private function creditedRequiredSeconds(DailyTimeReview $review): int
    {
        if ($review->employee?->paid_without_tracking) {
            return $this->requiredSeconds($review);
        }
        $period = $review->relationLoaded('payrollPeriod')
            ? $review->payrollPeriod
            : $review->payrollPeriod()->first();
        $ptoSeconds = $period?->pto_included_in_total ? 0 : max((int) $review->pto_seconds, 0);
        $holidaySeconds = $period?->holiday_included_in_total ? 0 : max((int) $review->holiday_seconds, 0);
        $paidTimeNotTrackedSeconds = (! $review->hasUntrackedDirectAbsence()
            && ((int) $review->hubstaff_total_seconds > 0 || $review->usesMeasuredActivity()
            || ($period && app(TrackabiActivityCoverageService::class)->applies($period)))
            )
            ? max((int) $review->paid_time_not_tracked_seconds, 0)
            : 0;

        return min(
            $review->payrollTrackedSeconds()
                + $paidTimeNotTrackedSeconds
                + max((int) $review->justified_absence_seconds, 0)
                + $ptoSeconds
                + $holidaySeconds,
            $this->requiredSeconds($review),
        );
    }

    private function regularUnjustifiedSeconds(DailyTimeReview $review): int
    {
        if ($review->employee?->paid_without_tracking) {
            return 0;
        }
        if ($review->paid_day_off || $this->isFullyJustifiedAbsence($review)) {
            return 0;
        }

        if ($this->isPayableUnscheduledWork($review)) {
            return 0;
        }

        if (! $review->scheduled_work_day && $review->hasUntrackedDirectAbsence()) {
            return max($review->untrackedDayReviewSeconds() - (int) $review->justified_absence_seconds, 0);
        }

        $requiredSeconds = $review->assigned_overtime_fulfilled
            ? $this->requiredSeconds($review)
            : (int) $review->expected_ordinary_seconds;
        $paidTimeNotTrackedSeconds = (! $review->hasUntrackedDirectAbsence()
            && ((int) $review->hubstaff_total_seconds > 0 || $review->usesMeasuredActivity()
            || ($review->payrollPeriod && app(TrackabiActivityCoverageService::class)->applies($review->payrollPeriod)))
            )
            ? (int) $review->paid_time_not_tracked_seconds
            : 0;

        return max(
            $requiredSeconds
                - $review->payrollTrackedSeconds()
                - $paidTimeNotTrackedSeconds
                - (int) $review->justified_absence_seconds,
            0,
        );
    }

    private function isFullyJustifiedAbsence(DailyTimeReview $review): bool
    {
        if ($review->paid_day_off || (int) $review->justified_absence_seconds <= 0
            || (int) $review->unjustified_absence_seconds > 0) {
            return false;
        }

        $period = $review->payrollPeriod;
        $direct = $period && app(TrackabiActivityCoverageService::class)->applies($period);
        $hasMeasuredWork = $direct
            ? ($review->verified_tracked_seconds !== null
                ? (int) $review->verified_tracked_seconds > 0
                : (int) ($review->trackabiActivity?->measured_activity_seconds ?? 0) > 0)
            : (int) $review->hubstaff_total_seconds > 0;

        return ! $hasMeasuredWork;
    }

    private function isPayableUnscheduledWork(DailyTimeReview $review): bool
    {
        if ($review->payrollPeriod && app(TrackabiActivityCoverageService::class)->applies($review->payrollPeriod)
            && ! $review->scheduled_work_day) {
            $employee = $review->employee;
            if ($employee && (int) $employee->fixed_weekly_off_day === $review->date->dayOfWeekIso
                && $employee->fixed_weekly_off_from_date?->lte($review->date)) {
                return false;
            }
            if ((int) ($review->verified_tracked_seconds
                ?? $review->trackabiActivity?->measured_activity_seconds) <= 0) {
                return false;
            }
        }
        if ($review->payrollPeriod?->limit_payable_to_schedule) {
            return false;
        }
        if ($review->employee && $review->payrollPeriod
            && app(OctoberFirstHalfTimeCorrectionService::class)->isMarcoPeriod($review->payrollPeriod, $review->employee)
            && $review->date->isWeekend()) {
            return false;
        }
        if ($review->employee?->overtime_distribution_mode === 'weekly_flexible') {
            return false;
        }
        if (
            (int) $review->expected_ordinary_seconds > 0
            || (int) $review->hubstaff_total_seconds <= 0
            || $review->paid_day_off
        ) {
            return false;
        }

        $employee = $review->relationLoaded('employee')
            ? $review->employee
            : $review->employee()->first();

        if (! $employee) {
            return false;
        }

        return $this->scheduleTypeForReview($employee, $review) !== 'rotativa';
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function manualOvertime(PayrollPeriod $period, Employee $employee): array
    {
        $adjustments = PayrollOvertimeAdjustment::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->where('active', true)
            ->orderBy('id')
            ->get();
        $paidSeconds = 0;
        $paidAmount = 0.0;

        foreach ($adjustments as $adjustment) {
            $adjustmentSeconds = max((int) round((float) $adjustment->hours * 3600), 0);

            if ($adjustmentSeconds <= 0) {
                continue;
            }

            $paidSeconds += $adjustmentSeconds;
            $paidAmount += ($adjustmentSeconds / 3600) * (float) $adjustment->hourly_rate;
        }

        return [$paidSeconds, $paidAmount];
    }

    private function workedDays(Collection $reviews): float
    {
        return (float) $reviews->sum(function (DailyTimeReview $review): float {
            if ($review->expected_ordinary_seconds <= 0) {
                if ($review->employee && $review->payrollPeriod
                    && app(OctoberFirstHalfTimeCorrectionService::class)->isMarcoPeriod($review->payrollPeriod, $review->employee)
                    && (int) $review->possible_overtime_seconds > 0) {
                    return 1.0;
                }
                if (($review->paid_day_off || $this->isFullyJustifiedAbsence($review))
                    && $this->isRotatingSchedule($review->employee)) {
                    return 1.0;
                }

                return $this->regularPayableSeconds($review) > 0 ? 1.0 : 0.0;
            }

            return min($this->regularPayableSeconds($review) / $review->expected_ordinary_seconds, 1.0);
        });
    }

    private function redistributeAssignedOvertime(PayrollPeriod $period, Employee $employee): void
    {
        $direct = app(TrackabiActivityCoverageService::class)->applies($period);
        $reviews = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$period->starts_at, $period->ends_at])
            ->orderBy('date')
            ->get();

        if ($reviews->isEmpty()) {
            return;
        }

        $reviews->each(fn (DailyTimeReview $review) => $this->applyScheduleExpectation($review, $employee));

        if (app(SeptemberSecondHalfTimeCorrectionService::class)->isMarcoPeriod($period, $employee)
            || app(OctoberFirstHalfTimeCorrectionService::class)->isMarcoPeriod($period, $employee)) {
            $this->redistributeMarcoWeekendOvertime($period, $employee, $reviews);

            return;
        }

        if ($employee->overtime_distribution_mode === 'weekly_flexible' && ! $this->isRotatingSchedule($employee)) {
            $this->redistributeWeeklyFlexibleOvertime($period, $employee, $reviews);

            return;
        }

        if ($this->isRotatingSchedule($employee)) {
            $weeklyAssignedSeconds = $this->preassignedWeeklySeconds($employee);
            $workDays = max((int) ($employee->rotation_work_days ?: 4), 1);
            $dailyAssignedLimit = $weeklyAssignedSeconds > 0
                ? (int) ceil($weeklyAssignedSeconds / $workDays)
                : 0;
            $scheduledDays = $reviews->where('scheduled_work_day', true)->count();
            $periodAssignedSeconds = $this->preassignedPeriodSeconds($employee);

            if ($periodAssignedSeconds <= 0 && $weeklyAssignedSeconds > 0) {
                $periodAssignedSeconds = (int) ceil($scheduledDays / $workDays) * $weeklyAssignedSeconds;
            }

            $remainingSeconds = $periodAssignedSeconds;

            foreach ($reviews as $review) {
                $review->preassigned_overtime_seconds = $review->scheduled_work_day
                    ? min($dailyAssignedLimit, $remainingSeconds)
                    : 0;
                $remainingSeconds -= (int) $review->preassigned_overtime_seconds;

                $this->calculateDailyReview($review, $employee);
            }

            $this->applyPeriodSpecificPayrollCorrections($period, $employee);

            return;
        }

        $weeklyAssignedSeconds = $this->preassignedWeeklySeconds($employee);
        $dailyAssignedLimit = $weeklyAssignedSeconds > 0
            ? max(3600, (int) ceil($weeklyAssignedSeconds / 5))
            : 0;
        $rangeStart = $reviews->min(fn (DailyTimeReview $review) => $review->date->copy()->startOfWeek());
        $rangeEnd = $reviews->max(fn (DailyTimeReview $review) => $review->date->copy()->endOfWeek());
        $assignedOutsidePeriod = DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', '!=', $period->id)
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->get()
            ->groupBy(fn (DailyTimeReview $review): string => $this->weekKey($review->date))
            ->map(fn (Collection $weekReviews): int => (int) $weekReviews->sum('preassigned_overtime_seconds'));
        $periodRemainingSeconds = $this->preassignedPeriodSeconds($employee);
        $hasPeriodLimit = $periodRemainingSeconds > 0;

        $reviews
            ->groupBy(fn (DailyTimeReview $review): string => $this->weekKey($review->date))
            ->each(function (Collection $weekReviews, string $weekKey) use (
                $employee,
                $weeklyAssignedSeconds,
                $dailyAssignedLimit,
                $assignedOutsidePeriod,
                &$periodRemainingSeconds,
                $hasPeriodLimit,
                $direct,
                $period,
            ): void {
                $remainingSeconds = max(
                    $weeklyAssignedSeconds - (int) $assignedOutsidePeriod->get($weekKey, 0),
                    0,
                );

                if ($hasPeriodLimit) {
                    $remainingSeconds = min($remainingSeconds, $periodRemainingSeconds);
                }

                $prioritizeElalfSaturday = app(OctoberFirstHalfTimeCorrectionService::class)->isElalfPeriod($period, $employee);
                $orderedReviews = $weekReviews->sort(function (DailyTimeReview $left, DailyTimeReview $right) use ($direct, $prioritizeElalfSaturday): int {
                    if ($prioritizeElalfSaturday && $left->date->isSaturday() !== $right->date->isSaturday()) {
                        return $left->date->isSaturday() ? -1 : 1;
                    }
                    $confirmationComparison = (int) $right->assigned_overtime_fulfilled
                        <=> (int) $left->assigned_overtime_fulfilled;

                    if ($confirmationComparison !== 0) {
                        return $confirmationComparison;
                    }

                    $leftReviewed = $left->status !== 'pendiente'
                        || (int) $left->justified_absence_seconds > 0
                        || $left->paid_day_off;
                    $rightReviewed = $right->status !== 'pendiente'
                        || (int) $right->justified_absence_seconds > 0
                        || $right->paid_day_off;
                    $reviewedComparison = (int) $rightReviewed <=> (int) $leftReviewed;

                    if ($reviewedComparison !== 0) {
                        return $reviewedComparison;
                    }

                    $leftTracked = $direct ? (int) ($left->trackabiActivity?->measured_activity_seconds ?? 0) : (int) $left->hubstaff_total_seconds;
                    $rightTracked = $direct ? (int) ($right->trackabiActivity?->measured_activity_seconds ?? 0) : (int) $right->hubstaff_total_seconds;
                    $leftExcess = max($leftTracked - (int) $left->expected_ordinary_seconds, 0);
                    $rightExcess = max($rightTracked - (int) $right->expected_ordinary_seconds, 0);
                    $excessComparison = $rightExcess <=> $leftExcess;

                    return $excessComparison !== 0
                        ? $excessComparison
                        : $left->date->getTimestamp() <=> $right->date->getTimestamp();
                });

                foreach ($orderedReviews as $review) {
                    $isWorkday = $review->scheduled_work_day
                        && ($direct || (int) $review->hubstaff_total_seconds > 0)
                        && ! $review->paid_day_off
                        && $this->scheduleTypeForReview($employee, $review) !== 'rotativa';
                    $assignedSeconds = $isWorkday
                        ? min($dailyAssignedLimit, $remainingSeconds)
                        : 0;

                    $review->preassigned_overtime_seconds = $assignedSeconds;

                    $remainingSeconds -= $assignedSeconds;
                    if ($hasPeriodLimit) {
                        $periodRemainingSeconds = max($periodRemainingSeconds - $assignedSeconds, 0);
                    }
                }

                foreach ($weekReviews as $review) {
                    $this->calculateDailyReview($review, $employee);
                }
            });

        $this->applyPeriodSpecificPayrollCorrections($period, $employee);
    }

    private function redistributeMarcoWeekendOvertime(PayrollPeriod $period, Employee $employee, Collection $reviews): void
    {
        $weeklyAssignedSeconds = $this->preassignedWeeklySeconds($employee);
        $rangeStart = $reviews->min(fn (DailyTimeReview $review) => $review->date->copy()->startOfWeek());
        $rangeEnd = $reviews->max(fn (DailyTimeReview $review) => $review->date->copy()->endOfWeek());
        $paidOutsidePeriod = DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', '!=', $period->id)
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->get()
            ->groupBy(fn (DailyTimeReview $review): string => $this->weekKey($review->date))
            ->map(fn (Collection $days): int => (int) $days->sum('possible_overtime_seconds'));

        $reviews->groupBy(fn (DailyTimeReview $review): string => $this->weekKey($review->date))
            ->each(function (Collection $weekReviews, string $weekKey) use ($weeklyAssignedSeconds, $paidOutsidePeriod, $employee): void {
                $remaining = max($weeklyAssignedSeconds - (int) $paidOutsidePeriod->get($weekKey, 0), 0);
                $weekendDays = $weekReviews->filter(fn (DailyTimeReview $review): bool => $review->date->isWeekend())->sortBy('date')->values();
                $weekendDays->each(fn (DailyTimeReview $review) => $review->preassigned_overtime_seconds = 0);
                $payableWeekendDays = $weekendDays->reject(fn (DailyTimeReview $review): bool => $review->paid_day_off)->values();
                $weekendTracked = (int) $payableWeekendDays->sum(fn (DailyTimeReview $review): int => $review->payrollTrackedSeconds());
                $weekendBudget = min($weekendTracked, $remaining);
                $weekendLeft = $weekendBudget;

                foreach ($payableWeekendDays as $index => $review) {
                    $review->preassigned_overtime_seconds = $index === $payableWeekendDays->count() - 1
                        ? $weekendLeft
                        : min((int) floor($weekendBudget * $review->payrollTrackedSeconds() / max($weekendTracked, 1)), $weekendLeft);
                    $weekendLeft -= (int) $review->preassigned_overtime_seconds;
                }
                $remaining -= $weekendBudget;

                foreach ($weekReviews->filter(fn (DailyTimeReview $review): bool => ! $review->date->isWeekend())->sortBy('date') as $review) {
                    $available = $review->paid_day_off ? 0 : max($review->payrollTrackedSeconds() - (int) $review->expected_ordinary_seconds, 0);
                    $review->preassigned_overtime_seconds = min($available, $remaining);
                    $remaining -= (int) $review->preassigned_overtime_seconds;
                }

                foreach ($weekReviews as $review) {
                    $this->calculateDailyReview($review, $employee);
                }
            });
    }

    private function redistributeWeeklyFlexibleOvertime(PayrollPeriod $period, Employee $employee, Collection $reviews): void
    {
        $weeklyAssigned = $this->preassignedWeeklySeconds($employee);
        $rangeStart = $reviews->min(fn (DailyTimeReview $review) => $review->date->copy()->startOfWeek());
        $rangeEnd = $reviews->max(fn (DailyTimeReview $review) => $review->date->copy()->endOfWeek());
        $paidOutside = DailyTimeReview::query()->where('employee_id', $employee->id)
            ->where('payroll_period_id', '!=', $period->id)
            ->whereBetween('date', [$rangeStart, $rangeEnd])->get()
            ->groupBy(fn (DailyTimeReview $review): string => $this->weekKey($review->date))
            ->map(fn (Collection $days): int => (int) $days->sum('possible_overtime_seconds'));
        $policy = $this->trackabiPaidActivityService->paidTimePolicy($employee);
        $paidNonTrackable = ($policy['lunch_minutes'] + $policy['break_minutes']) * 60;

        $reviews->groupBy(fn (DailyTimeReview $review): string => $this->weekKey($review->date))
            ->each(function (Collection $weekReviews, string $weekKey) use ($weeklyAssigned, $paidOutside, $employee, $paidNonTrackable): void {
                $remaining = max($weeklyAssigned - (int) $paidOutside->get($weekKey, 0), 0);
                $ordered = $weekReviews->sort(function (DailyTimeReview $left, DailyTimeReview $right): int {
                    if ($left->date->isWeekend() !== $right->date->isWeekend()) {
                        return $left->date->isWeekend() ? -1 : 1;
                    }

                    return $left->date->getTimestamp() <=> $right->date->getTimestamp();
                });
                foreach ($ordered as $review) {
                    $measured = $review->verified_tracked_seconds
                        ?? $review->trackabiActivity?->measured_activity_seconds;
                    $regularTrackable = $review->scheduled_work_day
                        ? max((int) $review->expected_ordinary_seconds - $paidNonTrackable, 0)
                        : 0;
                    $available = $review->paid_day_off || $measured === null
                        ? 0 : max((int) $measured - $regularTrackable, 0);
                    $review->preassigned_overtime_seconds = min($available, $remaining);
                    $remaining -= (int) $review->preassigned_overtime_seconds;
                }
                foreach ($weekReviews as $review) {
                    $this->calculateDailyReview($review, $employee);
                }
            });
    }

    private function applyPeriodSpecificPayrollCorrections(PayrollPeriod $period, Employee $employee): void
    {
        app(JulySecondHalfPayrollCorrectionsService::class)->applyForEmployee($period, $employee);
        app(AugustSecondHalfPayrollCorrectionsService::class)->applyForEmployee($period, $employee);
        app(SeptemberFirstHalfPayrollCorrectionsService::class)->applyForEmployee($period, $employee);
    }

    private function weekKey(Carbon $date): string
    {
        return $date->copy()->startOfWeek()->toDateString();
    }

    private function syncEmployeeDailyReviews(
        PayrollPeriod $period,
        Employee $employee,
        Collection $entriesByEmployeeDate,
    ): void {
        $employee->loadMissing([
            'scheduleType',
            'workScheduleTemplate.days',
            'scheduleAssignments.template.days',
        ]);

        foreach (CarbonPeriod::create($period->starts_at, $period->ends_at) as $date) {
            $dateString = $date->toDateString();
            $entries = $entriesByEmployeeDate->get($employee->id.'|'.$dateString, collect());
            $workEntries = $entries->reject(fn (HubstaffTimeEntry $entry): bool =>
                $entry->source_provider === 'trackabi_api'
                && $dateString >= config('trackabi_direct.special_projects_from_date')
                && isset(config('trackabi_direct.special_projects')[(int) $entry->trackabi_project_id]));
            $hubstaffTotalSeconds = (int) $workEntries->sum('total_seconds');
            $idleSeconds = (int) $workEntries->sum('idle_seconds');

            $review = DailyTimeReview::query()
                ->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->whereDate('date', $dateString)
                ->first() ?? new DailyTimeReview([
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'date' => $dateString,
                ]);

            $isNew = ! $review->exists;
            $this->applyScheduleExpectation($review, $employee);

            $review->fill([
                'hubstaff_total_seconds' => $hubstaffTotalSeconds,
                'hubstaff_regular_seconds' => (int) $workEntries->sum('regular_seconds'),
                'hubstaff_idle_seconds' => $idleSeconds,
                'activity_percentage' => $this->weightedPercentage($workEntries, 'activity_percentage'),
                'idle_percentage' => $this->weightedPercentage($workEntries, 'idle_percentage'),
                'pto_seconds' => (int) $entries->sum('pto_seconds'),
                'holiday_seconds' => (int) $entries->sum('holiday_seconds'),
                'paid_break_seconds' => $this->paidBreakSeconds($entries),
            ]);

            if ($isNew) {
                $review->assigned_overtime_seconds = 0;
                $review->preassigned_overtime_seconds = 0;
                $review->additional_overtime_seconds = 0;
                $review->pending_idle_seconds = $idleSeconds;
                $review->justified_idle_seconds = 0;
                $review->unjustified_idle_seconds = $idleSeconds;
                $review->justified_absence_seconds = 0;
                $review->unjustified_absence_seconds = max(
                    (int) $review->expected_ordinary_seconds
                        - $hubstaffTotalSeconds
                        - ($hubstaffTotalSeconds > 0
                            ? (int) $review->paid_time_not_tracked_seconds
                            : 0),
                    0,
                );
                $review->approved_overtime_seconds = 0;
                $review->assigned_overtime_fulfilled = false;
                $review->paid_day_off = false;
                $review->status = 'pendiente';
            }

            $review->save();
        }

        $this->redistributeAssignedOvertime($period, $employee);
        $this->refreshPendingActivityBasis($period, $employee);
        app(TrackabiActivityCoverageService::class)->reconcileEmployee($period, $employee);
    }

    private function refreshPendingActivityBasis(PayrollPeriod $period, Employee $employee): void
    {
        $activities = TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->get()
            ->keyBy(fn (TrackabiDailyActivity $activity): string => $activity->activity_date->toDateString());
        if ($activities->isEmpty()) {
            return;
        }

        $reviews = DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->get();
        foreach ($reviews as $review) {
            $activity = $activities->get($review->date->toDateString());
            if (! $activity || $review->status !== 'pendiente' || $review->paid_day_off
                || $employee->paid_without_tracking || $review->lost_time_source !== 'trackabi_insights_activity'
                || (int) $review->supervisor_adjustment_seconds !== 0
                || (int) $review->justified_absence_seconds !== 0) {
                continue;
            }

            $target = max((int) $review->expected_hubstaff_seconds, 0);
            $measured = max((int) $activity->measured_activity_seconds, 0);
            $shortfall = $review->hasUntrackedDirectAbsence()
                ? $review->untrackedDayReviewSeconds()
                : max($target - $measured, 0);
            $review->update([
                'estimated_lost_seconds' => $shortfall,
                'unjustified_absence_seconds' => $shortfall,
                'lost_time_estimate_metadata' => [
                    ...($review->lost_time_estimate_metadata ?? []),
                    'target_seconds' => $target,
                    'measured_seconds' => $measured,
                ],
            ]);
            $this->calculateDailyReview($review, $employee);
        }
    }

    private function applyScheduleExpectation(DailyTimeReview $review, Employee $employee): void
    {
        $previousExpectedPaidSeconds = (int) $review->expected_paid_seconds;
        $previousTrackableSeconds = (int) $review->expected_hubstaff_seconds;
        $previousPaidNonTrackedSeconds = (int) $review->paid_time_not_tracked_seconds;
        $expectation = $this->scheduleExpectationService->forDate($employee, $review->date);
        $ordinarySeconds = (int) $expectation['expected_ordinary_seconds'];
        $preassignedOvertimeSeconds = max((int) $review->preassigned_overtime_seconds, 0);

        if (
            $preassignedOvertimeSeconds === 0
            && (int) $review->expected_paid_seconds === 0
            && (int) $review->assigned_overtime_seconds > 0
        ) {
            $preassignedOvertimeSeconds = (int) $review->assigned_overtime_seconds;
        }
        $minimumPaidSeconds = $ordinarySeconds + $preassignedOvertimeSeconds;
        $expectedPaidSeconds = max(
            (int) $expectation['configured_expected_paid_seconds'],
            $minimumPaidSeconds,
        );
        $expectedHubstaffSeconds = (int) $expectation['configured_expected_hubstaff_seconds'];

        if ($expectedHubstaffSeconds <= 0) {
            $expectedHubstaffSeconds = max(
                $expectedPaidSeconds - (int) $expectation['paid_time_not_tracked_seconds'],
                0,
            );
        }

        $review->scheduled_work_day = (bool) $expectation['scheduled_work_day'];
        $review->expected_ordinary_seconds = $ordinarySeconds;
        $review->expected_seconds = $ordinarySeconds;
        $review->assigned_overtime_seconds = $preassignedOvertimeSeconds;
        $review->expected_paid_seconds = $expectedPaidSeconds;
        $review->expected_hubstaff_seconds = $expectedHubstaffSeconds;
        $review->paid_time_not_tracked_seconds = (int) $expectation['paid_time_not_tracked_seconds'];

        if ($review->payrollPeriod && app(TrackabiActivityCoverageService::class)->applies($review->payrollPeriod)
            && $review->verified_tracked_seconds === null && $review->trackabiActivity) {
            if (($review->status !== 'pendiente'
                || (int) $review->supervisor_adjustment_seconds !== 0
                || (int) $review->justified_absence_seconds !== 0)
                && $previousExpectedPaidSeconds === $expectedPaidSeconds) {
                $review->paid_time_not_tracked_seconds = $previousPaidNonTrackedSeconds;
                $review->expected_hubstaff_seconds = $previousTrackableSeconds;
            } else {
                $measured = (int) $review->trackabiActivity->measured_activity_seconds;
                $allowance = $this->trackabiPaidActivityService->allowanceSeconds($review, $measured);
                $review->paid_time_not_tracked_seconds = $allowance;
                $review->expected_hubstaff_seconds = max($expectedPaidSeconds - $allowance, 0);
            }
        }

        app(SeptemberSecondHalfTimeCorrectionService::class)->adjustExpectation($review, $employee);
        app(OctoberFirstHalfTimeCorrectionService::class)->adjustExpectation($review, $employee);
    }

    private function isRotatingSchedule(Employee $employee): bool
    {
        $scheduleCode = $employee->relationLoaded('scheduleType')
            ? $employee->scheduleType?->code
            : $employee->scheduleType()->value('code');

        return $scheduleCode === 'rotativa';
    }

    private function scheduleTypeForReview(Employee $employee, DailyTimeReview $review): string
    {
        return (string) $this->scheduleExpectationService->forDate($employee, $review->date)['schedule_type'];
    }

    private function preassignedWeeklySeconds(Employee $employee): int
    {
        $hours = (float) $employee->preassigned_overtime_weekly_hours
            ?: (float) $employee->overtime_hours;

        return max((int) round($hours * 3600), 0);
    }

    private function preassignedPeriodSeconds(Employee $employee): int
    {
        return max((int) round((float) $employee->preassigned_overtime_period_hours * 3600), 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manualReviewState(PayrollPeriod $period, ?Employee $employee = null): array
    {
        $fields = [
            'justified_idle_seconds',
            'unjustified_idle_seconds',
            'justified_absence_seconds',
            'unjustified_absence_seconds',
            'approved_overtime_seconds',
            'assigned_overtime_fulfilled',
            'paid_day_off',
            'overtime_rate_type_id',
            'overtime_comment',
            'supervisor_comment',
            'rrhh_comment',
            'status',
            'reviewed_by',
            'approved_by',
            'estimated_lost_seconds',
            'supervisor_adjustment_seconds',
            'lost_time_source',
            'lost_time_estimate_metadata',
            'reviewed_at',
            'verified_tracked_seconds',
            'verified_tracked_source',
            'verified_tracked_note',
            'verified_tracked_at',
        ];

        return DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->when($employee, fn ($query) => $query->where('employee_id', $employee->id))
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (DailyTimeReview $review): array => [
                $review->id => $this->normalizedManualReviewState($review, $fields),
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function normalizedManualReviewState(DailyTimeReview $review, array $fields): array
    {
        $state = $review->only($fields);
        if ($review->status === 'pendiente'
            && $review->payrollPeriod
            && app(TrackabiActivityCoverageService::class)->applies($review->payrollPeriod)
            && in_array($review->lost_time_source,
                [null, 'trackabi_insights_activity', 'historical_estimate', 'missing_activity', 'missing_timer'], true)
            && (int) $review->supervisor_adjustment_seconds === 0
            && (int) $review->justified_absence_seconds === 0) {
            unset($state['estimated_lost_seconds'], $state['unjustified_absence_seconds'],
                $state['lost_time_estimate_metadata'], $state['lost_time_source']);
        }
        $state['reviewed_at'] = $review->reviewed_at?->toDateTimeString();
        $state['verified_tracked_at'] = $review->verified_tracked_at?->toDateTimeString();

        if ($review->paid_day_off || $this->isPayableUnscheduledWork($review)) {
            $state['justified_absence_seconds'] = 0;
            $state['unjustified_absence_seconds'] = 0;
        }

        return $state;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function protectedPayrollDeductionState(PayrollPeriod $period): array
    {
        $fields = [
            'payroll_period_id',
            'employee_id',
            'amount',
            'description',
            'status',
            'created_by',
            'approved_by',
        ];

        return $period->payrollDeductions()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($deduction): array => [
                $deduction->id => $deduction->only($fields),
            ])
            ->all();
    }
}
