<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackabiProjectMapping;
use App\Services\ScheduleExpectationService;

class TrackabiCoverageReportService
{
    public function __construct(
        private readonly TrackabiDirectImportService $importer,
        private readonly ScheduleExpectationService $schedule,
    ) {}

    public function report(PayrollPeriod $period): array
    {
        $plan = $this->importer->preview($period);
        $campaignIds = TrackabiProjectMapping::query()->where('active', true)->pluck('campaign_id')->unique();
        $employees = Employee::query()->where('active', true)
            ->with(['workScheduleTemplate.days', 'scheduleType', 'scheduleAssignments.template.days'])->get();
        $required = $employees->filter(fn (Employee $employee): bool => ! $employee->paid_without_tracking
            && $campaignIds->contains($employee->campaign_id));
        $foundIds = collect($plan['entries'])->pluck('employee_id')->unique();
        $reviews = DailyTimeReview::query()->where('payroll_period_id', $period->id)->get()
            ->keyBy(fn (DailyTimeReview $review): string => $review->employee_id.'|'.$review->date->toDateString());
        $activities = TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)
            ->where('source', 'trackabi_insights_csv')->get()
            ->keyBy(fn (TrackabiDailyActivity $activity): string => $activity->employee_id.'|'.$activity->activity_date->toDateString());
        $days = [
            'scheduled_work' => 0, 'scheduled_off' => 0, 'with_time_entries' => 0,
            'activity_available' => 0, 'activity_zero' => 0, 'activity_missing' => 0,
            'justified_absence' => 0, 'confirmed_unjustified_absence' => 0,
        ];
        $entryDates = collect($plan['entries'])->mapWithKeys(fn (array $row): array => [
            $row['employee_id'].'|'.$row['date'] => true,
        ]);

        foreach ($required as $employee) {
            for ($date = $period->starts_at->copy(); $date->lte($period->ends_at); $date->addDay()) {
                $key = $employee->id.'|'.$date->toDateString();
                $review = $reviews->get($key);
                $expectation = $review ? null : $this->schedule->forDate($employee, $date);
                $scheduled = $review ? (bool) $review->scheduled_work_day : (bool) $expectation['scheduled_work_day'];
                $off = ! $scheduled || (bool) $review?->paid_day_off;
                $days[$off ? 'scheduled_off' : 'scheduled_work']++;
                if ($entryDates->has($key)) {
                    $days['with_time_entries']++;
                }
                if ($review && (int) $review->justified_absence_seconds > 0) {
                    $days['justified_absence']++;
                }
                if ($review && $review->status !== 'pendiente' && (int) $review->unjustified_absence_seconds > 0) {
                    $days['confirmed_unjustified_absence']++;
                }
                if ($off) {
                    continue;
                }
                $activity = $activities->get($key);
                if ($activity) {
                    $days['activity_available']++;
                    if ((int) $activity->measured_activity_seconds === 0) {
                        $days['activity_zero']++;
                    }
                } else {
                    $days['activity_missing']++;
                }
            }
        }

        $results = PayrollResult::query()->where('payroll_period_id', $period->id)
            ->whereIn('employee_id', $required->pluck('id'))->get();

        return [
            'period' => $period,
            'calendar_days' => $period->starts_at->copy()->diffInDays($period->ends_at) + 1,
            'payroll_employees' => $employees->count(),
            'tracking_required' => $required->count(),
            'tracking_exempt' => $employees->count() - $required->count(),
            'trackabi_employees_found' => $required->filter(fn (Employee $employee): bool => $foundIds->contains($employee->id))->count(),
            'missing_employees' => $required->reject(fn (Employee $employee): bool => $foundIds->contains($employee->id))
                ->map(fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->name])
                ->values()->all(),
            'unmatched_members' => $plan['unmapped_employees'],
            'daily_reviews' => $reviews->count(),
            'days' => $days,
            'payable_day_equivalents' => round((float) $results->sum('worked_days'), 2),
            'payroll_results' => $results->count(),
            'unmapped_projects' => $plan['unmapped_project_coverage'],
            'suspicious_reasons' => collect($plan['suspicious_entries'])->countBy('reason_code')->all(),
        ];
    }
}
