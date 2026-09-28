<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;

class HistoricalTimeAnalysis
{
    public function analyze(PayrollPeriod $target, array $periodIds): array
    {
        $historyIds = PayrollPeriod::whereIn('id', $periodIds)
            ->whereDate('ends_at', '<', $target->starts_at)->pluck('id');
        $entries = HubstaffTimeEntry::whereIn('payroll_period_id', $historyIds)
            ->where('active', true)->get()->groupBy(fn ($e) => $e->payroll_period_id.'|'.$e->employee_id.'|'.$e->date->toDateString());
        $reviews = DailyTimeReview::whereIn('payroll_period_id', $historyIds)
            ->where('scheduled_work_day', true)->where('paid_day_off', false)->get()->groupBy('employee_id');

        return Employee::where('active', true)->orderBy('name')->get()->map(function ($employee) use ($entries, $reviews): array {
            $samples = collect();
            $justifiedDays = 0;
            foreach ($reviews->get($employee->id, collect()) as $review) {
                $dayEntries = $entries->get($review->payroll_period_id.'|'.$employee->id.'|'.$review->date->toDateString(), collect());
                // Exclude Trackabi estimates and mixed-source days from historical evidence.
                if ($dayEntries->isEmpty() || $dayEntries->contains(fn ($entry) => ! in_array($entry->source_provider, [null, 'hubstaff_csv'], true))) {
                    continue;
                }
                $tracked = (int) $dayEntries->sum('total_seconds');
                $expected = max((int) $review->expected_hubstaff_seconds, 0);
                if ($tracked <= 0 || $expected <= 0) {
                    continue;
                }
                $loss = max($expected - $tracked, 0);
                $samples->push($loss);
                if ($loss > 0 && $review->justified_absence_seconds > 0) {
                    $justifiedDays++;
                }
            }
            $losses = $samples->filter(fn ($seconds) => $seconds > 0)->values();

            return [
                'employee' => $employee->name,
                'days' => $samples->count(),
                'short_days' => $losses->count(),
                'percentage' => $samples->isEmpty() ? null : round(100 * $losses->count() / $samples->count(), 1),
                'median_short_seconds' => $losses->isEmpty() ? null : (int) round($losses->median()),
                'max_short_seconds' => $losses->isEmpty() ? null : $losses->max(),
                'days_with_justification' => $justifiedDays,
            ];
        })->all();
    }
}
