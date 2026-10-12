<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackingImportAlert;

class TrackabiActivityCoverageService
{
    public function applies(PayrollPeriod $period): bool
    {
        $cutover = config('trackabi_direct.cutover_date');

        return is_string($cutover) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $cutover)
            && $period->starts_at->toDateString() >= $cutover
            && config('trackabi.provider') === 'direct';
    }

    public function reconcileEmployee(PayrollPeriod $period, Employee $employee): void
    {
        if (! $this->applies($period) || $period->status === 'cerrado') {
            return;
        }

        $activityDates = TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->where('source', 'trackabi_insights_csv')
            ->pluck('activity_date')->map(fn ($date) => substr((string) $date, 0, 10))->flip();

        foreach (DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->get() as $review) {
            $date = $review->date->toDateString();
            $required = ($review->scheduled_work_day && (int) $review->expected_hubstaff_seconds > 0
                || $review->hasUntrackedDirectAbsence())
                && ! $review->paid_day_off && ! $employee->paid_without_tracking;
            $hasActivity = $activityDates->has($date);
            $resolvedManually = ($review->status !== 'pendiente' && $review->lost_time_source !== 'historical_estimate')
                || $review->verified_tracked_seconds !== null
                || ((int) $review->justified_absence_seconds > 0
                    && (int) $review->justified_absence_seconds >= $review->untrackedDayReviewSeconds());
            $key = ['payroll_period_id' => $period->id, 'code' => 'TRACKABI_ACTIVITY_MISSING',
                'external_id' => 'activity:'.$employee->id.':'.$date];

            if (! $required || $hasActivity || $resolvedManually) {
                TrackingImportAlert::query()->where($key)->where('resolved', false)->update(['resolved' => true]);

                continue;
            }

            if ($review->status === 'pendiente' && in_array($review->lost_time_source, [null, 'historical_estimate', 'missing_activity', 'missing_timer'], true)) {
                $missingTimer = $review->hasUntrackedDirectAbsence();
                $review->update([
                    'lost_time_source' => $missingTimer ? 'missing_timer' : 'missing_activity',
                    'estimated_lost_seconds' => $missingTimer ? $review->untrackedDayReviewSeconds() : 0,
                    'supervisor_adjustment_seconds' => 0,
                    'unjustified_absence_seconds' => $missingTimer ? $review->untrackedDayReviewSeconds() : 0,
                    'lost_time_estimate_metadata' => ['activity_status' => $missingTimer ? 'no_timer' : 'missing'],
                ]);
            }

            TrackingImportAlert::query()->updateOrCreate($key, [
                'source_provider' => 'trackabi_api',
                'context' => ['employee' => $employee->name, 'employee_id' => $employee->id,
                    'date' => $date, 'review_id' => $review->id],
                'resolved' => false,
            ]);
        }
    }

    public function summary(PayrollPeriod $period): array
    {
        $counts = ['expected' => 0, 'available' => 0, 'zero' => 0, 'missing' => 0, 'legacy_historical' => 0];
        if (! $this->applies($period)) {
            return $counts;
        }

        $activities = TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)
            ->where('source', 'trackabi_insights_csv')->get()
            ->keyBy(fn (TrackabiDailyActivity $activity) => $activity->employee_id.'|'.$activity->activity_date->toDateString());

        foreach (DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('scheduled_work_day', true)->where('expected_hubstaff_seconds', '>', 0)
            ->with('employee')->get() as $review) {
            if ($review->employee?->paid_without_tracking || $review->paid_day_off) {
                continue;
            }
            $counts['expected']++;
            if ($review->lost_time_source === 'historical_estimate') {
                $counts['legacy_historical']++;
            }
            $activity = $activities->get($review->employee_id.'|'.$review->date->toDateString());
            if ($activity) {
                $counts['available']++;
                if ((int) $activity->measured_activity_seconds === 0) {
                    $counts['zero']++;
                }
            } elseif (($review->status === 'pendiente' || $review->lost_time_source === 'historical_estimate')
                && $review->verified_tracked_seconds === null
                && (int) $review->justified_absence_seconds < (int) $review->expected_ordinary_seconds) {
                $counts['missing']++;
            }
        }

        return $counts;
    }
}
