<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\Employee;

class TrackabiPaidActivityService
{
    public function creditedMeasuredSeconds(DailyTimeReview $review): ?int
    {
        $measured = $review->verified_tracked_seconds ?? $review->trackabiActivity?->measured_activity_seconds;
        if ($measured === null) {
            return null;
        }

        $credited = max((int) $measured, 0) + max((int) $review->paid_time_not_tracked_seconds, 0);
        $expected = max((int) $review->expected_paid_seconds, 0);

        return $expected > 0 ? min($expected, $credited) : $credited;
    }

    public function allowanceSeconds(DailyTimeReview $review, int $measuredSeconds): int
    {
        if ($measuredSeconds <= 0 || $review->paid_day_off || ! $review->employee
            || $review->employee->paid_without_tracking) {
            return 0;
        }

        $employee = $review->employee;
        $expected = max((int) $review->expected_paid_seconds, 0);
        $missing = max($expected - $measuredSeconds, 0);
        if ($expected === 0 || $missing === 0) {
            return 0;
        }

        $policy = $this->paidTimePolicy($employee);

        return min($missing, max($policy['lunch_minutes'] + $policy['break_minutes'], 0) * 60);
    }

    public function trackableTargetSeconds(DailyTimeReview $review, int $measuredSeconds): int
    {
        return max((int) $review->expected_paid_seconds - $this->allowanceSeconds($review, $measuredSeconds), 0);
    }

    /** @return array{lunch_minutes: int, break_minutes: int} */
    private function paidTimePolicy(Employee $employee): array
    {
        $campaign = strtoupper(trim((string) $employee->campaign?->name));
        $policies = config('trackabi_direct.insights_paid_time_policies', []);
        $special = config('trackabi_direct.insights_special_paid_time_member_ids', []);
        $policy = $campaign === 'PALMETTO'
            ? ($special[(int) $employee->trackabi_member_id] ?? $policies[$campaign] ?? null)
            : ($policies[$campaign] ?? null);

        return [
            'lunch_minutes' => max((int) ($policy['lunch_minutes'] ?? max(
                (int) $employee->paid_lunch_minutes_per_workday,
                (int) config('trackabi_direct.insights_paid_lunch_minutes', 60),
            )), 0),
            'break_minutes' => max((int) ($policy['break_minutes'] ?? max(
                (int) $employee->paid_break_minutes_per_workday,
                (int) config('trackabi_direct.insights_paid_break_minutes', 15),
            )), 0),
        ];
    }
}
