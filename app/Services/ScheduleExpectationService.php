<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\WorkScheduleTemplate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ScheduleExpectationService
{
    /**
     * @return array{
     *     scheduled_work_day: bool,
     *     expected_ordinary_seconds: int,
     *     configured_expected_hubstaff_seconds: int,
     *     configured_expected_paid_seconds: int,
     *     paid_time_not_tracked_seconds: int,
     *     schedule_type: string
     * }
     */
    public function forDate(Employee $employee, Carbon $date): array
    {
        $assignment = $this->assignmentForDate($employee, $date);
        $template = $assignment?->template
            ?? $employee->workScheduleTemplate;
        $scheduleType = $template?->schedule_type
            ?: $employee->scheduleType?->code
            ?: 'diurna';

        $scheduledWorkDay = $scheduleType === 'rotativa'
            ? $this->isRotatingWorkDay($employee, $assignment, $date)
            : $this->isTemplateWorkDay($template, $date);
        $expectedOrdinarySeconds = $scheduledWorkDay
            ? $this->expectedOrdinarySeconds($employee, $template, $assignment, $date, $scheduleType)
            : 0;
        $paidTimeNotTrackedSeconds = $scheduledWorkDay
            ? $this->paidTimeNotTrackedSeconds($employee)
            : 0;

        return [
            'scheduled_work_day' => $scheduledWorkDay,
            'expected_ordinary_seconds' => $expectedOrdinarySeconds,
            'configured_expected_hubstaff_seconds' => $scheduledWorkDay
                ? $this->hoursToSeconds((float) $employee->hubstaff_expected_hours_per_workday)
                : 0,
            'configured_expected_paid_seconds' => $scheduledWorkDay
                ? $this->hoursToSeconds((float) $employee->paid_hours_per_workday)
                : 0,
            'paid_time_not_tracked_seconds' => $paidTimeNotTrackedSeconds,
            'schedule_type' => $scheduleType,
        ];
    }

    public function activeAssignment(Employee $employee): ?EmployeeScheduleAssignment
    {
        if ($employee->relationLoaded('scheduleAssignments')) {
            return $this->latestAssignment(
                $employee->scheduleAssignments->filter(
                    fn (EmployeeScheduleAssignment $assignment): bool => $assignment->active
                        && (! $assignment->ends_at || $assignment->ends_at->gte(now()->startOfDay())),
                ),
            );
        }

        return EmployeeScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()->toDateString()))
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    private function assignmentForDate(Employee $employee, Carbon $date): ?EmployeeScheduleAssignment
    {
        if ($employee->relationLoaded('scheduleAssignments')) {
            return $this->latestAssignment(
                $employee->scheduleAssignments->filter(
                    fn (EmployeeScheduleAssignment $assignment): bool => $assignment->active
                        && (! $assignment->starts_at || $assignment->starts_at->lte($date))
                        && (! $assignment->ends_at || $assignment->ends_at->gte($date)),
                ),
            );
        }

        return EmployeeScheduleAssignment::query()
            ->with('template.days')
            ->where('employee_id', $employee->id)
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhereDate('starts_at', '<=', $date))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $date))
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    private function latestAssignment(Collection $assignments): ?EmployeeScheduleAssignment
    {
        return $assignments
            ->sort(function (EmployeeScheduleAssignment $left, EmployeeScheduleAssignment $right): int {
                $startComparison = ($right->starts_at?->getTimestamp() ?? 0)
                    <=> ($left->starts_at?->getTimestamp() ?? 0);

                return $startComparison !== 0
                    ? $startComparison
                    : $right->id <=> $left->id;
            })
            ->first();
    }

    private function isTemplateWorkDay(?WorkScheduleTemplate $template, Carbon $date): bool
    {
        if (! $template) {
            return true;
        }

        $day = $template->days->firstWhere('day_number', $date->dayOfWeekIso);

        return (bool) $day?->is_working_day;
    }

    private function isRotatingWorkDay(
        Employee $employee,
        ?EmployeeScheduleAssignment $assignment,
        Carbon $date,
    ): bool {
        $cycleStart = $assignment?->cycle_start_date ?: $employee->schedule_cycle_anchor_date;

        if (! $cycleStart) {
            return false;
        }

        $workDays = max((int) ($assignment?->rotation_work_days ?: $employee->rotation_work_days ?: 4), 1);
        $restDays = max((int) ($assignment?->rotation_rest_days ?: $employee->rotation_rest_days ?: 4), 0);
        $cycleLength = max($workDays + $restDays, 1);
        $daysSinceStart = (int) $cycleStart
            ->copy()
            ->startOfDay()
            ->diffInDays($date->copy()->startOfDay(), false);
        $cycleDay = (($daysSinceStart % $cycleLength) + $cycleLength) % $cycleLength;

        return $cycleDay < $workDays;
    }

    private function expectedOrdinarySeconds(
        Employee $employee,
        ?WorkScheduleTemplate $template,
        ?EmployeeScheduleAssignment $assignment,
        Carbon $date,
        string $scheduleType,
    ): int {
        if ($template) {
            $dayNumber = $scheduleType === 'rotativa'
                ? $this->rotatingTemplateDayNumber($employee, $assignment, $date)
                : $date->dayOfWeekIso;
            $templateDay = $template->days->firstWhere('day_number', $dayNumber);

            if ($templateDay) {
                return $templateDay->is_working_day
                    ? max((int) $templateDay->expected_seconds, 0)
                    : 0;
            }
        }

        $dailyHours = (float) $employee->daily_hours;

        if ($dailyHours > 0) {
            return $this->hoursToSeconds($dailyHours);
        }

        $weeklyHours = (float) $employee->ordinary_weekly_hours
            ?: (float) $employee->weekly_hours;

        return $weeklyHours > 0
            ? $this->hoursToSeconds($weeklyHours / 5)
            : 0;
    }

    private function rotatingTemplateDayNumber(
        Employee $employee,
        ?EmployeeScheduleAssignment $assignment,
        Carbon $date,
    ): int {
        $cycleStart = $assignment?->cycle_start_date ?: $employee->schedule_cycle_anchor_date;
        $workDays = max((int) ($assignment?->rotation_work_days ?: $employee->rotation_work_days ?: 4), 1);

        if (! $cycleStart) {
            return 1;
        }

        $daysSinceStart = (int) $cycleStart
            ->copy()
            ->startOfDay()
            ->diffInDays($date->copy()->startOfDay(), false);

        return ((($daysSinceStart % $workDays) + $workDays) % $workDays) + 1;
    }

    private function paidTimeNotTrackedSeconds(Employee $employee): int
    {
        $minutes = 0;

        if (! $employee->lunch_included_in_hubstaff_total) {
            $minutes += (int) $employee->paid_lunch_minutes_per_workday;
        }

        if (! $employee->breaks_included_in_hubstaff_total) {
            $minutes += (int) $employee->paid_break_minutes_per_workday;
        }

        return max($minutes * 60, 0);
    }

    private function hoursToSeconds(float $hours): int
    {
        return max((int) round($hours * 3600), 0);
    }
}
