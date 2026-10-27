<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackingImportAlert;

class TrackabiPaidTimeComplianceService
{
    private const CODES = [
        'TRACKABI_LUNCH_NOT_TRACKED', 'TRACKABI_LUNCH_EXCESS',
        'TRACKABI_PAID_BREAK_NOT_TRACKED', 'TRACKABI_PAID_BREAK_EXCESS',
        'TRACKABI_BREAK_OCCURRENCE_MISMATCH',
    ];

    public function __construct(private readonly TrackabiPaidActivityService $paidActivity) {}

    public function inspect(PayrollPeriod $period): array
    {
        $from = max($period->starts_at->toDateString(), (string) config('trackabi_direct.special_projects_from_date'));
        $entries = HubstaffTimeEntry::query()->where('payroll_period_id', $period->id)
            ->where('source_provider', 'trackabi_api')->where('active', true)
            ->whereDate('date', '>=', $from)->get()
            ->groupBy(fn (HubstaffTimeEntry $entry): string => $entry->employee_id.'|'.$entry->date->toDateString());
        $activities = TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)->get()
            ->keyBy(fn (TrackabiDailyActivity $activity): string => $activity->employee_id.'|'.$activity->activity_date->toDateString());
        $rows = [];
        foreach (DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->whereDate('date', '>=', $from)->with('employee.campaign')->get() as $review) {
            $employee = $review->employee;
            if (! $employee || $employee->paid_without_tracking || $review->paid_day_off
                || ! $review->scheduled_work_day || (int) $review->expected_ordinary_seconds <= 0) {
                continue;
            }
            $dayEntries = $entries->get($employee->id.'|'.$review->date->toDateString(), collect());
            $specialIds = array_keys(config('trackabi_direct.special_projects', []));
            $hasWork = $dayEntries->contains(fn (HubstaffTimeEntry $entry): bool =>
                ! in_array((int) $entry->trackabi_project_id, $specialIds, true));
            if (! $hasWork && (int) ($activities->get($employee->id.'|'.$review->date->toDateString())?->measured_activity_seconds ?? 0) === 0) {
                continue;
            }

            $policy = $this->paidActivity->paidTimePolicy($employee);
            $special = $dayEntries->filter(fn (HubstaffTimeEntry $entry): bool =>
                in_array((int) $entry->trackabi_project_id, $specialIds, true));
            $lunch = (int) $special->where('trackabi_project_id', 81120)->sum('total_seconds');
            $break10 = $special->where('trackabi_project_id', 81118);
            $break15 = $special->where('trackabi_project_id', 81119);
            $break = (int) $break10->sum('total_seconds') + (int) $break15->sum('total_seconds');
            $occurrences = $break10->count() + $break15->count();
            $expectedLunch = $policy['lunch_minutes'] * 60;
            $expectedBreak = $policy['break_minutes'] * 60;
            $codes = [];
            if ($expectedLunch > 0 && $lunch < $expectedLunch) {
                $codes[] = 'TRACKABI_LUNCH_NOT_TRACKED';
            }
            if ($lunch > $expectedLunch) {
                $codes[] = 'TRACKABI_LUNCH_EXCESS';
            }
            if ($expectedBreak > 0 && $break < $expectedBreak) {
                $codes[] = 'TRACKABI_PAID_BREAK_NOT_TRACKED';
            }
            if ($break > $expectedBreak) {
                $codes[] = 'TRACKABI_PAID_BREAK_EXCESS';
            }
            if ($break > 0 && $policy['break_occurrences'] > 0
                && $occurrences !== $policy['break_occurrences']) {
                $codes[] = 'TRACKABI_BREAK_OCCURRENCE_MISMATCH';
            }
            $rows[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->name,
                'date' => $review->date->toDateString(),
                'campaign' => $employee->campaign?->name,
                'lunch_entitlement_seconds' => $expectedLunch,
                'lunch_tracked_seconds' => $lunch,
                'lunch_variance_seconds' => $lunch - $expectedLunch,
                'break_entitlement_seconds' => $expectedBreak,
                'break_minutes_per_occurrence' => $policy['break_minutes_per_occurrence'],
                'break_expected_occurrences' => $policy['break_occurrences'],
                'break_tracked_occurrences' => $occurrences,
                'break_10_tracked_seconds' => (int) $break10->sum('total_seconds'),
                'break_15_tracked_seconds' => (int) $break15->sum('total_seconds'),
                'break_tracked_seconds' => $break,
                'break_variance_seconds' => $break - $expectedBreak,
                'status' => $codes === [] ? 'COMPLIANT' : implode(', ', $codes),
                'alert_codes' => $codes,
            ];
        }

        return $rows;
    }

    public function reconcile(PayrollPeriod $period): array
    {
        $rows = $this->inspect($period);
        foreach ($rows as $row) {
            foreach (self::CODES as $code) {
                $key = [
                    'payroll_period_id' => $period->id,
                    'code' => $code,
                    'external_id' => 'paid-time:'.$row['employee_id'].':'.$row['date'],
                ];
                if (in_array($code, $row['alert_codes'], true)) {
                    TrackingImportAlert::query()->updateOrCreate($key, [
                        'source_provider' => 'trackabi_api', 'resolved' => false,
                        'context' => $row,
                    ]);
                } else {
                    TrackingImportAlert::query()->where($key)->update(['resolved' => true]);
                }
            }
        }

        return $rows;
    }
}
