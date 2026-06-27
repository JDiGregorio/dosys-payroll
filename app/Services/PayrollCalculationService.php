<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PaidTimeProject;
use App\Models\PayrollOvertimeAdjustment;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
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
            $review->payable_seconds = $this->paidDayOffSeconds($review);
        } else {
            $review->payable_seconds = $this->regularPayableSeconds($review)
                + $this->paidAssignedOvertimeSeconds($review);
        }

        $review->difference_seconds = (int) $review->hubstaff_total_seconds
            - (int) $review->expected_hubstaff_seconds;
        $review->possible_overtime_seconds = $this->paidAssignedOvertimeSeconds($review);

        $review->save();
    }

    public function recalculateEmployeePayrollResult(PayrollPeriod $period, Employee $employee): void
    {
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
        $payableSeconds = $regularPayableSeconds + $preassignedOvertimeSeconds;
        $hourlyRate = $this->employeeHourlyRate($employee);
        $monthlySalary = $this->employeeMonthlySalary($employee, $hourlyRate);
        $biweeklySalary = $this->employeeSemiMonthlySalary($employee, $monthlySalary);
        $dailyRate = $this->employeeDailyRate($employee, $monthlySalary);
        $fallbackOvertimeRate = $this->employeeOvertimeRate($employee, $hourlyRate);
        $preassignedOvertimeAmount = ($preassignedOvertimeSeconds / 3600) * $fallbackOvertimeRate;

        $unapprovedExcessSeconds = (int) $reviews->sum(
            fn (DailyTimeReview $review): int => max(
                (int) $review->hubstaff_total_seconds - (int) $review->expected_hubstaff_seconds,
                0,
            ),
        );
        [$manualOvertimeSeconds, $manualOvertimeAmount] = $this->manualOvertime(
            $period,
            $employee,
            $unapprovedExcessSeconds,
        );
        $overtimeAmount = round($preassignedOvertimeAmount + $manualOvertimeAmount, 2);

        $qaBonus = $this->bonusService->amountByType($period, $employee, ['qa']);
        $productivityBonus = $this->bonusService->amountByType($period, $employee, ['productivity']);
        $timeManagementBonus = $this->bonusService->amountByType($period, $employee, ['time_management']);
        $referredBonus = $this->bonusService->amountByType($period, $employee, ['referred']);
        $adjustmentBonus = $this->bonusService->amountByType($period, $employee, ['adjustment']);
        $extraBonuses = $this->bonusService->amountByType($period, $employee, ['manual', 'other']);
        $internetSubsidy = ($employee->location === 'remote' ? (float) $employee->internet_subsidy_amount : 0.0)
            + $this->bonusService->amountByType($period, $employee, ['internet_subsidy']);
        $payrollCompensation = 0.0;
        $extrasTotal = round($overtimeAmount + $internetSubsidy + $qaBonus + $productivityBonus + $timeManagementBonus + $extraBonuses + $referredBonus + $adjustmentBonus + $payrollCompensation, 2);
        $workedDays = $this->workedDays($reviews);
        $scheduledDays = (float) $reviews->where('scheduled_work_day', true)->count();
        $regularLostSeconds = (int) $reviews->sum(
            fn (DailyTimeReview $review): int => $this->regularUnjustifiedSeconds($review),
        );
        $salaryCalculationMethod = $employee->salary_calculation_method ?: 'hourly_actual_hours';
        $idleLostSeconds = $salaryCalculationMethod === 'semi_monthly_fixed_with_deductions'
            ? (int) $reviews->sum(fn (DailyTimeReview $review): int => min(
                max((int) $review->unjustified_idle_seconds, 0),
                max((int) $review->hubstaff_idle_seconds, 0),
            ))
            : 0;
        $overtimeLostSeconds = 0;
        $regularLostAmount = ($regularLostSeconds / 3600) * $hourlyRate;
        $overtimeLostAmount = 0.0;
        $absenceDeduction = round($regularLostAmount, 2);
        $idleDeduction = round(($idleLostSeconds / 3600) * $hourlyRate, 2);
        $lostTimeSeconds = $regularLostSeconds + $idleLostSeconds + $overtimeLostSeconds;
        $lostTimeAmount = round($absenceDeduction + $idleDeduction + $overtimeLostAmount, 2);
        $expectedOrdinarySeconds = (int) $reviews->sum('expected_ordinary_seconds');
        $workedSalary = round(match ($salaryCalculationMethod) {
            'semi_monthly_fixed_with_deductions' => max(
                $biweeklySalary - $absenceDeduction - $idleDeduction,
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
            ], true) ? round($biweeklySalary, 2) : $workedSalary,
            'absence_deduction' => $absenceDeduction,
            'idle_deduction' => $idleDeduction,
            'extra_bonuses_amount' => round($extraBonuses, 2),
            'referred_bonus_amount' => round($referredBonus, 2),
            'adjustment_bonus_amount' => round($adjustmentBonus, 2),
            'bonuses_amount' => round($extraBonuses + $referredBonus + $adjustmentBonus + $qaBonus + $productivityBonus + $timeManagementBonus + $internetSubsidy, 2),
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

            Employee::query()
                ->with([
                    'scheduleType',
                    'workScheduleTemplate.days',
                    'scheduleAssignments.template.days',
                    'tierLevel',
                ])
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
            $manualReviewState = $this->manualReviewState($period);
            $bonusState = $period->payrollBonuses()->orderBy('id')->get()->map->getAttributes()->all();
            $deductionState = $period->payrollDeductions()->orderBy('id')->get()->map->getAttributes()->all();
            $deductionIds = collect($deductionState)->pluck('id');
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

            if ($deductionState !== $period->payrollDeductions()
                ->whereKey($deductionIds)
                ->orderBy('id')
                ->get()
                ->map
                ->getAttributes()
                ->all()) {
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
            return (int) $review->expected_ordinary_seconds;
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

    private function hoursToSeconds(float $hours): int
    {
        return max((int) round($hours * 3600), 0);
    }

    private function creditedRequiredSeconds(DailyTimeReview $review): int
    {
        $period = $review->relationLoaded('payrollPeriod')
            ? $review->payrollPeriod
            : $review->payrollPeriod()->first();
        $ptoSeconds = $period?->pto_included_in_total ? 0 : max((int) $review->pto_seconds, 0);
        $holidaySeconds = $period?->holiday_included_in_total ? 0 : max((int) $review->holiday_seconds, 0);
        $paidTimeNotTrackedSeconds = (int) $review->hubstaff_total_seconds > 0
            ? max((int) $review->paid_time_not_tracked_seconds, 0)
            : 0;

        return min(
            max((int) $review->hubstaff_total_seconds, 0)
                + $paidTimeNotTrackedSeconds
                + max((int) $review->justified_absence_seconds, 0)
                + $ptoSeconds
                + $holidaySeconds,
            $this->requiredSeconds($review),
        );
    }

    private function regularUnjustifiedSeconds(DailyTimeReview $review): int
    {
        if ($review->paid_day_off || $this->isFullyJustifiedAbsence($review)) {
            return 0;
        }

        $requiredSeconds = $review->assigned_overtime_fulfilled
            ? $this->requiredSeconds($review)
            : (int) $review->expected_ordinary_seconds;
        $paidTimeNotTrackedSeconds = (int) $review->hubstaff_total_seconds > 0
            ? (int) $review->paid_time_not_tracked_seconds
            : 0;

        return max(
            $requiredSeconds
                - (int) $review->hubstaff_total_seconds
                - $paidTimeNotTrackedSeconds
                - (int) $review->justified_absence_seconds,
            0,
        );
    }

    private function isFullyJustifiedAbsence(DailyTimeReview $review): bool
    {
        return ! $review->paid_day_off
            && (int) $review->hubstaff_total_seconds <= 0
            && (int) $review->justified_absence_seconds > 0
            && (int) $review->unjustified_absence_seconds <= 0;
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function manualOvertime(PayrollPeriod $period, Employee $employee, int $availableSeconds): array
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
            $applicableSeconds = min($adjustmentSeconds, max($availableSeconds - $paidSeconds, 0));

            if ($applicableSeconds <= 0) {
                break;
            }

            $paidSeconds += $applicableSeconds;
            $paidAmount += ($applicableSeconds / 3600) * (float) $adjustment->hourly_rate;
        }

        return [$paidSeconds, $paidAmount];
    }

    private function workedDays(Collection $reviews): float
    {
        return (float) $reviews->sum(function (DailyTimeReview $review): float {
            if ($review->expected_ordinary_seconds <= 0) {
                return $this->regularPayableSeconds($review) > 0 ? 1.0 : 0.0;
            }

            return min($this->regularPayableSeconds($review) / $review->expected_ordinary_seconds, 1.0);
        });
    }

    private function redistributeAssignedOvertime(PayrollPeriod $period, Employee $employee): void
    {
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
            ): void {
                $remainingSeconds = max(
                    $weeklyAssignedSeconds - (int) $assignedOutsidePeriod->get($weekKey, 0),
                    0,
                );

                if ($hasPeriodLimit) {
                    $remainingSeconds = min($remainingSeconds, $periodRemainingSeconds);
                }

                $orderedReviews = $weekReviews->sort(function (DailyTimeReview $left, DailyTimeReview $right): int {
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

                    $leftExcess = max((int) $left->hubstaff_total_seconds - (int) $left->expected_ordinary_seconds, 0);
                    $rightExcess = max((int) $right->hubstaff_total_seconds - (int) $right->expected_ordinary_seconds, 0);
                    $excessComparison = $rightExcess <=> $leftExcess;

                    return $excessComparison !== 0
                        ? $excessComparison
                        : $left->date->getTimestamp() <=> $right->date->getTimestamp();
                });

                foreach ($orderedReviews as $review) {
                    $isWorkday = $review->scheduled_work_day
                        && (int) $review->hubstaff_total_seconds > 0
                        && ! $review->paid_day_off;
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
            $hubstaffTotalSeconds = (int) $entries->sum('total_seconds');
            $idleSeconds = (int) $entries->sum('idle_seconds');

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
                'hubstaff_regular_seconds' => (int) $entries->sum('regular_seconds'),
                'hubstaff_idle_seconds' => $idleSeconds,
                'activity_percentage' => $this->weightedPercentage($entries, 'activity_percentage'),
                'idle_percentage' => $this->weightedPercentage($entries, 'idle_percentage'),
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
    }

    private function applyScheduleExpectation(DailyTimeReview $review, Employee $employee): void
    {
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
    }

    private function isRotatingSchedule(Employee $employee): bool
    {
        $scheduleCode = $employee->relationLoaded('scheduleType')
            ? $employee->scheduleType?->code
            : $employee->scheduleType()->value('code');

        return $scheduleCode === 'rotativa';
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

        if ($review->paid_day_off) {
            $state['justified_absence_seconds'] = 0;
            $state['unjustified_absence_seconds'] = 0;
        }

        return $state;
    }
}
