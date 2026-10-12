<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;

class TrackabiPaidActivityService
{
    public function creditedMeasuredSeconds(DailyTimeReview $review): ?int
    {
        $measured = $review->trackabiActivity?->measured_activity_seconds;
        if ($measured === null) {
            return null;
        }

        $credited = max((int) $measured, 0) + max((int) $review->paid_time_not_tracked_seconds, 0);
        $expected = max((int) $review->expected_paid_seconds, 0);

        return $expected > 0 ? min($expected, $credited) : $credited;
    }

    public function allowanceSeconds(DailyTimeReview $review, int $measuredSeconds): int
    {
        if ($measuredSeconds <= 0 || $review->paid_day_off || $review->employee?->paid_without_tracking) {
            return 0;
        }

        $employee = $review->employee;
        $expected = max((int) $review->expected_paid_seconds, 0);
        $missing = max($expected - $measuredSeconds, 0);
        if ($expected === 0 || $missing === 0) {
            return 0;
        }

        $lunch = max((int) $employee?->paid_lunch_minutes_per_workday,
            (int) config('trackabi_direct.insights_paid_lunch_minutes', 60));
        $break = max((int) $employee?->paid_break_minutes_per_workday,
            (int) config('trackabi_direct.insights_paid_break_minutes', 15));
        $combinedSeconds = max($lunch + $break, 0) * 60;
        $rotating = $employee?->scheduleType?->code === 'rotativa';

        if ($rotating) {
            $rotatingLunchSeconds = max($lunch, 0) * 60;

            return min($missing, $missing > $combinedSeconds ? $combinedSeconds : $rotatingLunchSeconds);
        }

        return $missing > $combinedSeconds ? min($combinedSeconds, $missing) : 0;
    }

    public function trackableTargetSeconds(DailyTimeReview $review, int $measuredSeconds): int
    {
        return max((int) $review->expected_paid_seconds - $this->allowanceSeconds($review, $measuredSeconds), 0);
    }
}
