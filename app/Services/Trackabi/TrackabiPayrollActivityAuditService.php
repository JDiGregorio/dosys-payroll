<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackingImportAlert;

class TrackabiPayrollActivityAuditService
{
    public function report(PayrollPeriod $period): array
    {
        $reviews = DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->with('employee')->get()
            ->sortBy(fn (DailyTimeReview $review): string => ($review->employee?->name ?? '').'|'.$review->date->toDateString());
        $activity = TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)->get()
            ->keyBy(fn (TrackabiDailyActivity $day): string => $day->employee_id.'|'.$day->activity_date->toDateString());
        $specialIds = array_map('intval', array_keys(config('trackabi_direct.special_projects', [])));
        $logged = HubstaffTimeEntry::query()->where('payroll_period_id', $period->id)
            ->where('source_provider', 'trackabi_api')->where('active', true)->get()
            ->reject(fn (HubstaffTimeEntry $entry): bool => in_array((int) $entry->trackabi_project_id, $specialIds, true))
            ->groupBy(fn (HubstaffTimeEntry $entry): string => $entry->employee_id.'|'.$entry->date->toDateString())
            ->map(fn ($entries): int => (int) $entries->sum('total_seconds'));
        $buckets = array_fill_keys(['0 min', '1-5 min', '6-10 min', '11-15 min', '16-20 min', '21-28 min', '>28 min'], 0);
        $rows = [];
        $missing = 0;
        $unexplained = 0;
        $aboveRange = 0;

        foreach ($reviews as $review) {
            $employee = $review->employee;
            if (! $employee || $employee->paid_without_tracking) {
                continue;
            }
            $key = $employee->id.'|'.$review->date->toDateString();
            $day = $activity->get($key);
            $scheduled = $review->scheduled_work_day && ! $review->paid_day_off;
            $measured = $day?->measured_activity_seconds;
            $target = max((int) $review->expected_hubstaff_seconds, 0);
            $gap = $scheduled && $measured !== null ? max($target - (int) $measured, 0) : null;
            $justified = max((int) $review->justified_absence_seconds, 0)
                + max((int) $review->supervisor_adjustment_seconds, 0);
            $fullCredit = $gap !== null && $gap > 0
                && (int) $review->payable_seconds >= (int) $review->expected_paid_seconds
                && $justified < $gap;
            if ($scheduled && $measured === null) {
                $missing++;
            }
            if ($gap !== null) {
                $minutes = intdiv($gap + 59, 60);
                $bucket = match (true) {
                    $gap === 0 => '0 min',
                    $minutes <= 5 => '1-5 min',
                    $minutes <= 10 => '6-10 min',
                    $minutes <= 15 => '11-15 min',
                    $minutes <= 20 => '16-20 min',
                    $minutes <= 28 => '21-28 min',
                    default => '>28 min',
                };
                $buckets[$bucket]++;
                $unexplained += (int) $fullCredit;
                $aboveRange += (int) ($gap > 28 * 60);
            }
            $rows[] = [
                'employee' => $employee->name,
                'employee_id' => $employee->id,
                'date' => $review->date->toDateString(),
                'scheduled' => (bool) $review->scheduled_work_day,
                'expected_regular_seconds' => max((int) $review->expected_ordinary_seconds, 0),
                'approved_overtime_seconds' => max((int) $review->preassigned_overtime_seconds, 0),
                'expected_trackable_seconds' => $target,
                'desktop_activity_seconds' => $measured === null ? null : (int) $measured,
                'raw_gap_seconds' => $gap,
                'justified_seconds' => $justified,
                'final_lost_seconds' => max((int) $review->expected_paid_seconds - (int) $review->payable_seconds, 0),
                'logged_seconds' => (int) $logged->get($key, 0),
                'source' => $day ? 'trackabi_insights_csv' : 'activity_missing',
                'status' => $review->status,
                'unexplained_full_credit' => $fullCredit,
                'above_observed_range' => $gap !== null && $gap > 28 * 60,
            ];
        }

        return [
            'rows' => $rows,
            'gap_distribution' => $buckets,
            'activity_missing' => $missing,
            'unexplained_full_credit' => $unexplained,
            'gap_above_observed_range' => $aboveRange,
        ];
    }

    public function reconcileAlerts(PayrollPeriod $period): array
    {
        if (! in_array($period->status, ['borrador', 'importado', 'en_revision'], true)) {
            throw new \RuntimeException('No se modifican alertas de períodos cerrados.');
        }
        $report = $this->report($period);
        foreach ($report['rows'] as $row) {
            foreach (['TRACKABI_UNEXPLAINED_FULL_CREDIT' => 'unexplained_full_credit',
                'TRACKABI_ACTIVITY_GAP_ABOVE_OBSERVED_RANGE' => 'above_observed_range'] as $code => $flag) {
                $key = [
                    'payroll_period_id' => $period->id,
                    'code' => $code,
                    'external_id' => 'activity-audit:'.$row['employee_id'].':'.$row['date'],
                ];
                if ($row[$flag] && $row['status'] === 'pendiente') {
                    TrackingImportAlert::query()->updateOrCreate($key, [
                        'source_provider' => 'trackabi_insights_csv',
                        'resolved' => false,
                        'context' => $row,
                    ]);
                } else {
                    TrackingImportAlert::query()->where($key)->update(['resolved' => true]);
                }
            }
        }

        return $report;
    }
}
