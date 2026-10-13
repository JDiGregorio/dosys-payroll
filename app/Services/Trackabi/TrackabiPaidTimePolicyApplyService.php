<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\PayrollPeriod;
use App\Models\TrackabiDailyActivity;
use App\Services\PayrollCalculationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TrackabiPaidTimePolicyApplyService
{
    public function __construct(
        private readonly TrackabiPaidTimePolicyPreviewService $preview,
        private readonly TrackabiActivityCoverageService $coverage,
        private readonly PayrollCalculationService $payroll,
    ) {}

    public function apply(PayrollPeriod $period): int
    {
        return DB::transaction(function () use ($period): int {
            $lockedPeriod = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($lockedPeriod->status === 'cerrado' || ! $this->coverage->applies($lockedPeriod)) {
                throw new RuntimeException('Se requiere un período abierto de Trackabi Direct.');
            }

            DailyTimeReview::query()->where('payroll_period_id', $lockedPeriod->id)
                ->orderBy('id')->lockForUpdate()->pluck('id');

            $rows = $this->preview->preview($lockedPeriod);
            $unsafe = $rows->filter(fn (array $row): bool => $row['status'] !== 'pendiente'
                && $row['current_allowance_seconds'] !== $row['proposed_allowance_seconds']
                && ! $row['reviewed_eligible']);
            if ($unsafe->isNotEmpty()) {
                throw new RuntimeException('Hay '.$unsafe->count().' revisiones aplicadas que requieren revisión manual; no se modificó el período.');
            }

            $changed = 0;
            foreach ($rows as $row) {
                if (! $row['reviewed_eligible'] || $row['current_allowance_seconds'] === $row['proposed_allowance_seconds']) {
                    continue;
                }

                $review = DailyTimeReview::query()->lockForUpdate()->findOrFail($row['review_id']);
                $activity = TrackabiDailyActivity::query()->where('payroll_period_id', $lockedPeriod->id)
                    ->where('employee_id', $review->employee_id)->whereDate('activity_date', $review->date)
                    ->where('source', 'trackabi_insights_csv')->lockForUpdate()->first();
                if ($review->status !== $row['status']
                    || ! $activity || (int) $activity->measured_activity_seconds !== $row['measured_seconds']
                    || (int) $review->expected_paid_seconds !== $row['expected_paid_seconds']
                    || (int) $review->paid_time_not_tracked_seconds !== $row['current_allowance_seconds']
                    || (int) $review->justified_absence_seconds !== $row['old_justified_seconds']
                    || (int) $review->unjustified_absence_seconds !== $row['old_unjustified_seconds']
                    || (int) $review->supervisor_adjustment_seconds !== 0
                    || (int) $review->estimated_lost_seconds !== $row['old_loss_seconds']) {
                    throw new RuntimeException('Una revisión cambió durante el ajuste. No se modificó el período.');
                }

                $metadata = $review->lost_time_estimate_metadata ?? [];
                $review->update([
                    'paid_time_not_tracked_seconds' => $row['proposed_allowance_seconds'],
                    'expected_hubstaff_seconds' => max((int) $review->expected_paid_seconds - $row['proposed_allowance_seconds'], 0),
                    'estimated_lost_seconds' => $row['proposed_shortfall_seconds'],
                    'justified_absence_seconds' => $row['proposed_justified_seconds'],
                    'unjustified_absence_seconds' => $row['proposed_shortfall_seconds'] - $row['proposed_justified_seconds'],
                    'lost_time_estimate_metadata' => [
                        ...$metadata,
                        'target_seconds' => max((int) $review->expected_paid_seconds - $row['proposed_allowance_seconds'], 0),
                        'paid_time_policy_adjustment' => [
                            'adjusted_at' => now()->toIso8601String(),
                            'previous_allowance_seconds' => $row['current_allowance_seconds'],
                            'previous_loss_seconds' => $row['old_loss_seconds'],
                            'previous_justified_seconds' => $row['old_justified_seconds'],
                            'new_allowance_seconds' => $row['proposed_allowance_seconds'],
                            'new_loss_seconds' => $row['proposed_shortfall_seconds'],
                            'new_justified_seconds' => $row['proposed_justified_seconds'],
                        ],
                    ],
                ]);
                $changed++;
            }

            $this->payroll->recalculatePeriodPreservingManual($lockedPeriod);

            return $changed;
        });
    }
}
