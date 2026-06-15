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
    ) {}

    public function generateDailyReviews(PayrollPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            DailyTimeReview::query()
                ->where('payroll_period_id', $period->id)
                ->where(function ($query) use ($period): void {
                    $query->whereDate('date', '<', $period->starts_at)
                        ->orWhereDate('date', '>', $period->ends_at);
                })
                ->delete();

            $entriesByEmployeeDate = HubstaffTimeEntry::query()
                ->with('employee')
                ->where('payroll_period_id', $period->id)
                ->whereNotNull('employee_id')
                ->get()
                ->groupBy(fn (HubstaffTimeEntry $entry) => $entry->employee_id.'|'.$entry->date->toDateString());

            $employees = Employee::query()->where('active', true)->get();

            foreach ($employees as $employee) {
                $this->syncEmployeeDailyReviews($period, $employee, $entriesByEmployeeDate);
            }

            $this->generatePayrollDeductions($period);
        });
    }

    public function regenerateEmployeeDailyReviews(
        PayrollPeriod $period,
        Employee $employee,
        bool $resetReviewState = false,
    ): void {
        DB::transaction(function () use ($period, $employee, $resetReviewState): void {
            if ($resetReviewState) {
                DailyTimeReview::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->update([
                        'justified_idle_seconds' => 0,
                        'unjustified_idle_seconds' => 0,
                        'justified_absence_seconds' => 0,
                        'unjustified_absence_seconds' => 0,
                        'approved_overtime_seconds' => 0,
                        'assigned_overtime_fulfilled' => false,
                        'paid_day_off' => false,
                        'status' => 'pendiente',
                        'supervisor_comment' => null,
                        'rrhh_comment' => null,
                        'reviewed_by' => null,
                        'approved_by' => null,
                    ]);
            }

            $entriesByEmployeeDate = HubstaffTimeEntry::query()
                ->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
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
            $review->expected_seconds = $this->expectedDailySeconds($employee, $review->date);
        }

        foreach ([
            'pending_idle_seconds',
            'justified_idle_seconds',
            'unjustified_idle_seconds',
            'justified_absence_seconds',
            'unjustified_absence_seconds',
            'approved_overtime_seconds',
        ] as $field) {
            $review->{$field} = max((int) $review->{$field}, 0);
        }

        $review->justified_idle_seconds = 0;
        $review->unjustified_idle_seconds = (int) $review->hubstaff_idle_seconds;
        $review->pending_idle_seconds = (int) $review->hubstaff_idle_seconds;

        if ($review->paid_day_off) {
            $review->justified_absence_seconds = 0;
            $review->unjustified_absence_seconds = 0;
            $review->payable_seconds = $review->expected_seconds;
        } else {
            $totalMissingSeconds = $this->totalMissingSeconds($review);
            $effectiveJustifiedSeconds = min((int) $review->justified_absence_seconds, $totalMissingSeconds);
            $review->unjustified_absence_seconds = max($totalMissingSeconds - $effectiveJustifiedSeconds, 0);
            $review->payable_seconds = $this->regularPayableSeconds($review)
                + $this->paidAssignedOvertimeSeconds($review);
        }

        $review->difference_seconds = (int) $review->hubstaff_total_seconds - $this->requiredSeconds($review);
        $review->possible_overtime_seconds = $this->paidAssignedOvertimeSeconds($review);

        if ($review->approved_overtime_seconds > $review->possible_overtime_seconds) {
            $review->approved_overtime_seconds = $review->possible_overtime_seconds;
        }

        $review->save();
    }

    public function recalculateEmployeePayrollResult(PayrollPeriod $period, Employee $employee): void
    {
        $reviews = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
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
        $biweeklySalary = $monthlySalary / 2;
        $dailyRate = $monthlySalary / 30;
        $fallbackOvertimeRate = (float) $employee->overtime_hourly_rate ?: $hourlyRate * 1.25;
        $preassignedOvertimeAmount = ($preassignedOvertimeSeconds / 3600) * $fallbackOvertimeRate;

        $unapprovedExcessSeconds = (int) $reviews->sum(
            fn (DailyTimeReview $review): int => max(
                (int) $review->hubstaff_total_seconds - $this->requiredSeconds($review),
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
        $workedSalary = round(($regularPayableSeconds / 3600) * $hourlyRate, 2);
        $regularLostSeconds = (int) $reviews->sum(
            fn (DailyTimeReview $review): int => $this->regularUnjustifiedSeconds($review),
        );
        $overtimeLostSeconds = 0;
        $regularLostAmount = ($regularLostSeconds / 3600) * $hourlyRate;
        $overtimeLostAmount = 0.0;
        $lostTimeSeconds = $regularLostSeconds + $overtimeLostSeconds;
        $lostTimeAmount = round($regularLostAmount + $overtimeLostAmount, 2);
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

        PayrollResult::query()->updateOrCreate(
            [
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
            ],
            [
                'monthly_salary' => round($monthlySalary, 2),
                'biweekly_salary_amount' => round($biweeklySalary, 2),
                'daily_rate' => round($dailyRate, 4),
                'hourly_rate' => round($hourlyRate, 4),
                'overtime_hourly_rate' => round($fallbackOvertimeRate, 4),
                'worked_days' => round($workedDays, 2),
                'worked_hours' => round($payableSeconds / 3600, 2),
                'expected_seconds' => (int) $reviews->sum('expected_seconds'),
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
                'base_salary_amount' => $workedSalary,
                'extra_bonuses_amount' => round($extraBonuses, 2),
                'referred_bonus_amount' => round($referredBonus, 2),
                'adjustment_bonus_amount' => round($adjustmentBonus, 2),
                'bonuses_amount' => round($extraBonuses + $referredBonus + $adjustmentBonus + $qaBonus + $productivityBonus + $timeManagementBonus + $internetSubsidy, 2),
                'overtime_seconds' => $preassignedOvertimeSeconds + $manualOvertimeSeconds,
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
                'status' => $period->status === 'aprobado' ? 'aprobado' : 'borrador',
            ],
        );
    }

    public function recalculatePayrollResults(PayrollPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $this->generatePayrollDeductions($period);

            Employee::query()
                ->whereHas('dailyTimeReviews', fn ($query) => $query->where('payroll_period_id', $period->id))
                ->each(function (Employee $employee) use ($period): void {
                    $this->redistributeAssignedOvertime($period, $employee);
                    $this->recalculateEmployeePayrollResult($period, $employee);
                });
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
        return (float) $employee->hourly_rate
            ?: (float) $employee->tierLevel?->hourly_rate
            ?: 0.0;
    }

    private function employeeMonthlySalary(Employee $employee, float $hourlyRate): float
    {
        return (float) $employee->monthly_salary
            ?: (float) $employee->daily_hours * $hourlyRate * 30;
    }

    private function requiredSeconds(DailyTimeReview $review): int
    {
        return (int) $review->expected_seconds + (int) $review->assigned_overtime_seconds;
    }

    private function totalMissingSeconds(DailyTimeReview $review): int
    {
        return max($this->requiredSeconds($review) - (int) $review->hubstaff_total_seconds, 0);
    }

    private function regularPayableSeconds(DailyTimeReview $review): int
    {
        if ($review->paid_day_off) {
            return (int) $review->expected_seconds;
        }

        $creditedSeconds = $this->creditedRequiredSeconds($review);

        if (! $review->assigned_overtime_fulfilled) {
            return min($creditedSeconds, (int) $review->expected_seconds);
        }

        return min(
            max($creditedSeconds - $this->paidAssignedOvertimeSeconds($review), 0),
            (int) $review->expected_seconds,
        );
    }

    private function paidAssignedOvertimeSeconds(DailyTimeReview $review): int
    {
        if ($review->paid_day_off) {
            return 0;
        }

        $creditedSeconds = $this->creditedRequiredSeconds($review);
        $payableOvertimeSeconds = $review->assigned_overtime_fulfilled
            ? $creditedSeconds
            : max($creditedSeconds - (int) $review->expected_seconds, 0);

        return min(
            (int) $review->assigned_overtime_seconds,
            $payableOvertimeSeconds,
        );
    }

    private function creditedRequiredSeconds(DailyTimeReview $review): int
    {
        return min(
            (int) $review->hubstaff_total_seconds + (int) $review->justified_absence_seconds,
            $this->requiredSeconds($review),
        );
    }

    private function regularUnjustifiedSeconds(DailyTimeReview $review): int
    {
        if ($review->paid_day_off) {
            return 0;
        }

        $requiredSeconds = $review->assigned_overtime_fulfilled
            ? $this->requiredSeconds($review)
            : (int) $review->expected_seconds;

        return max(
            $requiredSeconds
                - (int) $review->hubstaff_total_seconds
                - (int) $review->justified_absence_seconds,
            0,
        );
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
            if ($review->expected_seconds <= 0) {
                return $this->regularPayableSeconds($review) > 0 ? 1.0 : 0.0;
            }

            return min($this->regularPayableSeconds($review) / $review->expected_seconds, 1.0);
        });
    }

    private function redistributeAssignedOvertime(PayrollPeriod $period, Employee $employee): void
    {
        $reviews = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->orderBy('date')
            ->get();

        if ($reviews->isEmpty()) {
            return;
        }

        $reviews->each(function (DailyTimeReview $review) use ($employee): void {
            $review->expected_seconds = $this->expectedDailySeconds($employee, $review->date);
        });

        if ($this->isRotatingSchedule($employee)) {
            foreach ($reviews as $review) {
                $isScheduledWorkday = $this->isScheduledRotatingWorkday($employee, $review->date);
                $review->assigned_overtime_seconds = $isScheduledWorkday ? 3600 : 0;

                if ($review->status === 'pendiente') {
                    $review->assigned_overtime_fulfilled = $isScheduledWorkday
                        && (int) $review->hubstaff_total_seconds >= $this->requiredSeconds($review);
                }

                $this->calculateDailyReview($review, $employee);
            }

            return;
        }

        $weeklyAssignedSeconds = max((int) round((float) $employee->overtime_hours * 3600), 0);
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
            ->map(fn (Collection $weekReviews): int => (int) $weekReviews->sum('assigned_overtime_seconds'));

        $reviews
            ->groupBy(fn (DailyTimeReview $review): string => $this->weekKey($review->date))
            ->each(function (Collection $weekReviews, string $weekKey) use (
                $employee,
                $weeklyAssignedSeconds,
                $dailyAssignedLimit,
                $assignedOutsidePeriod,
            ): void {
                $remainingSeconds = max(
                    $weeklyAssignedSeconds - (int) $assignedOutsidePeriod->get($weekKey, 0),
                    0,
                );

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

                    $leftExcess = max((int) $left->hubstaff_total_seconds - (int) $left->expected_seconds, 0);
                    $rightExcess = max((int) $right->hubstaff_total_seconds - (int) $right->expected_seconds, 0);
                    $excessComparison = $rightExcess <=> $leftExcess;

                    return $excessComparison !== 0
                        ? $excessComparison
                        : $left->date->getTimestamp() <=> $right->date->getTimestamp();
                });

                foreach ($orderedReviews as $review) {
                    $isWorkday = (int) $review->hubstaff_total_seconds > 0 && ! $review->paid_day_off;
                    $assignedSeconds = $isWorkday
                        ? min($dailyAssignedLimit, $remainingSeconds)
                        : 0;

                    $review->assigned_overtime_seconds = $assignedSeconds;

                    if ($assignedSeconds <= 0) {
                        $review->assigned_overtime_fulfilled = false;
                    } elseif (
                        $review->status === 'pendiente'
                        && ! $review->assigned_overtime_fulfilled
                    ) {
                        $review->assigned_overtime_fulfilled = (int) $review->hubstaff_total_seconds
                            >= (int) $review->expected_seconds + $assignedSeconds;
                    }

                    $remainingSeconds -= $assignedSeconds;
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
        foreach (CarbonPeriod::create($period->starts_at, $period->ends_at) as $date) {
            $dateString = $date->toDateString();
            $entries = $entriesByEmployeeDate->get($employee->id.'|'.$dateString, collect());
            $expectedSeconds = $this->expectedDailySeconds($employee, $date);
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

            $review->fill([
                'expected_seconds' => $expectedSeconds,
                'hubstaff_total_seconds' => $hubstaffTotalSeconds,
                'hubstaff_regular_seconds' => (int) $entries->sum('regular_seconds'),
                'hubstaff_idle_seconds' => $idleSeconds,
                'activity_percentage' => $this->weightedPercentage($entries, 'activity_percentage'),
                'idle_percentage' => $this->weightedPercentage($entries, 'idle_percentage'),
                'pto_seconds' => 0,
                'holiday_seconds' => 0,
                'paid_break_seconds' => $this->paidBreakSeconds($entries),
                'status' => $review->status ?: 'pendiente',
            ]);

            if ($isNew) {
                $review->assigned_overtime_seconds = 0;
                $review->pending_idle_seconds = $idleSeconds;
                $review->justified_idle_seconds = 0;
                $review->unjustified_idle_seconds = $idleSeconds;
                $review->justified_absence_seconds = 0;
                $review->unjustified_absence_seconds = max($expectedSeconds - $hubstaffTotalSeconds, 0);
                $review->approved_overtime_seconds = 0;
                $review->assigned_overtime_fulfilled = false;
                $review->paid_day_off = false;
            }

            $review->save();
        }

        $this->redistributeAssignedOvertime($period, $employee);
    }

    private function expectedDailySeconds(Employee $employee, Carbon $date): int
    {
        if ($this->isRotatingSchedule($employee)) {
            return $this->isScheduledRotatingWorkday($employee, $date)
                ? 10 * 3600
                : 0;
        }

        return max((int) round((float) $employee->daily_hours * 3600), 0);
    }

    private function isRotatingSchedule(Employee $employee): bool
    {
        $scheduleCode = $employee->relationLoaded('scheduleType')
            ? $employee->scheduleType?->code
            : $employee->scheduleType()->value('code');

        return $scheduleCode === 'rotativa'
            && $employee->schedule_cycle_anchor_date !== null;
    }

    private function isScheduledRotatingWorkday(Employee $employee, Carbon $date): bool
    {
        if (! $employee->schedule_cycle_anchor_date) {
            return false;
        }

        $daysSinceAnchor = (int) $employee->schedule_cycle_anchor_date
            ->copy()
            ->startOfDay()
            ->diffInDays($date->copy()->startOfDay(), false);
        $cycleDay = (($daysSinceAnchor % 8) + 8) % 8;

        return $cycleDay < 4;
    }
}
