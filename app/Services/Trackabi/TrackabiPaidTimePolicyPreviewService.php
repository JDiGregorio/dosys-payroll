<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\PayrollPeriod;
use App\Models\TrackabiDailyActivity;
use Illuminate\Support\Collection;

class TrackabiPaidTimePolicyPreviewService
{
    public function __construct(private readonly TrackabiPaidActivityService $paidActivity) {}

    public function preview(PayrollPeriod $period): Collection
    {
        $activities = TrackabiDailyActivity::query()
            ->where('payroll_period_id', $period->id)
            ->where('source', 'trackabi_insights_csv')
            ->get()
            ->keyBy(fn (TrackabiDailyActivity $activity): string => $activity->employee_id.'|'.$activity->activity_date->toDateString());

        return DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->with('employee.campaign')
            ->get()
            ->map(function (DailyTimeReview $review) use ($activities): ?array {
                $activity = $activities->get($review->employee_id.'|'.$review->date->toDateString());
                $measured = (int) ($activity?->measured_activity_seconds ?? 0);
                if ($measured <= 0 || ! $review->employee || $review->employee->paid_without_tracking
                    || $review->paid_day_off || (int) $review->expected_paid_seconds <= 0) {
                    return null;
                }

                $proposedAllowance = $this->paidActivity->allowanceSeconds($review, $measured);
                $currentAllowance = (int) $review->paid_time_not_tracked_seconds;
                $expected = (int) $review->expected_paid_seconds;
                $eligible = $review->status === 'pendiente' && $review->lost_time_source === 'trackabi_insights_activity'
                    && $review->verified_tracked_seconds === null
                    && (int) $review->supervisor_adjustment_seconds === 0
                    && (int) $review->justified_absence_seconds === 0;
                $oldLoss = max((int) $review->estimated_lost_seconds, 0);
                $justified = max((int) $review->justified_absence_seconds, 0);
                $newLoss = max($expected - $measured - $proposedAllowance, 0);
                $metadataMeasured = $review->lost_time_estimate_metadata['measured_seconds'] ?? null;
                $reviewedEligible = $review->status !== 'pendiente'
                    && $review->lost_time_source === 'trackabi_insights_activity'
                    && $review->verified_tracked_seconds === null
                    && (int) $review->supervisor_adjustment_seconds === 0
                    && ($oldLoss > 0 || $newLoss === 0)
                    && $justified <= $oldLoss
                    && (int) $review->unjustified_absence_seconds === $oldLoss - $justified
                    && $metadataMeasured !== null && (int) $metadataMeasured === $measured;
                $newJustified = $oldLoss > 0
                    ? min(intdiv($newLoss * $justified + intdiv($oldLoss, 2), $oldLoss), $newLoss)
                    : 0;

                return [
                    'review_id' => $review->id,
                    'employee' => $review->employee->name,
                    'campaign' => $review->employee->campaign?->name ?? 'Sin campaña',
                    'date' => $review->date->toDateString(),
                    'status' => $review->status,
                    'eligible' => $eligible,
                    'reviewed_eligible' => $reviewedEligible,
                    'measured_seconds' => $measured,
                    'expected_paid_seconds' => $expected,
                    'current_allowance_seconds' => $currentAllowance,
                    'proposed_allowance_seconds' => $proposedAllowance,
                    'current_shortfall_seconds' => max($expected - $measured - $currentAllowance, 0),
                    'proposed_shortfall_seconds' => $newLoss,
                    'old_loss_seconds' => $oldLoss,
                    'old_justified_seconds' => $justified,
                    'old_unjustified_seconds' => (int) $review->unjustified_absence_seconds,
                    'proposed_justified_seconds' => $newJustified,
                ];
            })
            ->filter()
            ->values();
    }
}
