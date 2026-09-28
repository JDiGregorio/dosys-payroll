<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HistoricalLostTimeEstimator
{
    public function __construct(private readonly PayrollCalculationService $payroll) {}

    public function plan(PayrollPeriod $period, ?int $employeeId = null, bool $refreshPending = false): Collection
    {
        $historyIds = PayrollPeriod::query()
            ->whereIn('id', config('trackabi.historical_loss_period_ids', []))
            ->whereDate('ends_at', '<', $period->starts_at)
            ->pluck('id');

        $historicalEntries = HubstaffTimeEntry::query()
            ->whereIn('payroll_period_id', $historyIds)
            ->where('active', true)
            ->get()
            ->groupBy(fn (HubstaffTimeEntry $entry): string => $this->key($entry->payroll_period_id, $entry->employee_id, $entry->date->toDateString()));

        $history = DailyTimeReview::query()
            ->whereIn('payroll_period_id', $historyIds)
            ->where('scheduled_work_day', true)
            ->where('paid_day_off', false)
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->get()
            ->groupBy('employee_id')
            ->map(function (Collection $reviews) use ($historicalEntries): array {
                $losses = collect();
                foreach ($reviews as $review) {
                    $entries = $historicalEntries->get($this->key($review->payroll_period_id, $review->employee_id, $review->date->toDateString()), collect());
                    if ($entries->isEmpty() || $entries->contains(fn (HubstaffTimeEntry $entry): bool => ! in_array($entry->source_provider, [null, 'hubstaff_csv'], true))) {
                        continue;
                    }

                    $tracked = (int) $entries->sum('total_seconds');
                    $expected = max((int) $review->expected_hubstaff_seconds, 0);
                    if ($tracked <= 0 || $expected <= 0) {
                        continue;
                    }

                    $loss = max($expected - $tracked, 0);
                    if ($loss <= 7200) {
                        $losses->push($loss);
                    }
                }

                $average = $losses->isEmpty() ? 0 : $losses->avg();

                return [
                    'sample_days' => $losses->count(),
                    'short_days' => $losses->filter(fn (int $loss): bool => $loss > 0)->count(),
                    'average_seconds' => $average < 60 ? 0 : (int) round($average / 60) * 60,
                ];
            });

        $trackabiDays = HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('active', true)
            ->whereIn('source_provider', ['trackabi', 'trackabi_api'])
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->get()
            ->groupBy(fn (HubstaffTimeEntry $entry): string => $this->key($entry->payroll_period_id, $entry->employee_id, $entry->date->toDateString()));

        $candidates = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->where('scheduled_work_day', true)
            ->where('hubstaff_total_seconds', '>', 0)
            ->with('employee')
            ->get()
            ->filter(function (DailyTimeReview $review) use ($trackabiDays): bool {
                $expected = max((int) $review->expected_hubstaff_seconds, 0);

                return $review->employee?->paid_without_tracking !== true
                    && $expected > 0
                    && (int) $review->hubstaff_total_seconds >= $expected
                    && $trackabiDays->has($this->key($review->payroll_period_id, $review->employee_id, $review->date->toDateString()));
            })
            ->groupBy('employee_id');

        $maxLoss = max((int) config('trackabi.estimated_loss_max_minutes', 28), 0) * 60;

        return $candidates->flatMap(function (Collection $days, int $employeeId) use ($history, $historyIds, $maxLoss, $refreshPending): array {
            $stats = $history->get($employeeId);
            if (! $stats || $stats['sample_days'] < 10 || $stats['short_days'] === 0 || $stats['average_seconds'] === 0 || $maxLoss === 0) {
                return [];
            }

            $frequency = min($stats['short_days'] / $stats['sample_days'], 0.7);
            $count = min($days->count() > 1 ? $days->count() - 1 : 1, max(1, (int) round($days->count() * $frequency)));
            $ordered = $days->sortBy(fn (DailyTimeReview $review): int => $this->daySeed($review, 'selection'))
                ->take($count)
                ->filter(fn (DailyTimeReview $review): bool => $review->status === 'pendiente'
                    && ! $review->paid_day_off
                    && (int) $review->justified_absence_seconds === 0
                    && ($review->lost_time_source === null || ($refreshPending
                        && $review->lost_time_source === 'historical_estimate'
                        && (int) $review->supervisor_adjustment_seconds === 0)));

            return $ordered->map(function (DailyTimeReview $review) use ($stats, $historyIds, $maxLoss): array {
                $factor = 55 + ($this->daySeed($review, 'minutes') % 46);
                $seconds = max(60, (int) round(min($stats['average_seconds'], $maxLoss) * $factor / 100 / 60) * 60);

                return [
                    'review_id' => $review->id,
                    'previous_estimated_lost_seconds' => (int) $review->estimated_lost_seconds,
                    'employee_id' => $review->employee_id,
                    'employee' => $review->employee->name,
                    'date' => $review->date->toDateString(),
                    'raw_seconds' => (int) $review->hubstaff_total_seconds,
                    'expected_seconds' => (int) $review->expected_hubstaff_seconds,
                    'overtime_seconds' => (int) $review->preassigned_overtime_seconds,
                    'estimated_lost_seconds' => $seconds,
                    'metadata' => [
                        'method' => 'historical_hubstaff_v1',
                        'history_period_ids' => $historyIds->all(),
                        'sample_days' => $stats['sample_days'],
                        'short_days' => $stats['short_days'],
                        'average_seconds' => $stats['average_seconds'],
                        'max_daily_seconds' => $maxLoss,
                    ],
                ];
            })->filter(fn (array $row): bool => $row['previous_estimated_lost_seconds'] !== $row['estimated_lost_seconds']
                || $days->firstWhere('id', $row['review_id'])?->lost_time_source === null)->all();
        })->values();
    }

    public function apply(PayrollPeriod $period, Collection $plan, bool $refreshPending = false): int
    {
        return DB::transaction(function () use ($period, $plan, $refreshPending): int {
            if ($period->fresh()->status === 'cerrado') {
                throw new \RuntimeException('No se puede estimar tiempo en un período cerrado.');
            }

            $applied = 0;
            $employees = collect();
            foreach ($plan as $row) {
                $review = DailyTimeReview::query()->lockForUpdate()->findOrFail($row['review_id']);
                if ($review->payroll_period_id !== $period->id || $review->status !== 'pendiente' || $review->paid_day_off
                    || ($review->lost_time_source !== null && ! ($refreshPending
                        && $review->lost_time_source === 'historical_estimate'
                        && (int) $review->supervisor_adjustment_seconds === 0))
                    || (int) $review->justified_absence_seconds > 0
                    || (int) $review->hubstaff_total_seconds !== $row['raw_seconds']
                    || (int) $review->expected_hubstaff_seconds !== $row['expected_seconds']) {
                    continue;
                }

                $review->update([
                    'estimated_lost_seconds' => $row['estimated_lost_seconds'],
                    'supervisor_adjustment_seconds' => 0,
                    'lost_time_source' => 'historical_estimate',
                    'lost_time_estimate_metadata' => $row['metadata'],
                ]);
                $employees->push($review->employee_id);
                $applied++;
            }

            $employees->unique()->each(function (int $id) use ($period): void {
                $employee = Employee::query()->findOrFail($id);
                $this->payroll->recalculateEmployeePreservingManual($period, $employee);
            });

            return $applied;
        });
    }

    private function key(int $periodId, int $employeeId, string $date): string
    {
        return $periodId.'|'.$employeeId.'|'.$date;
    }

    private function daySeed(DailyTimeReview $review, string $purpose): int
    {
        return hexdec(substr(hash('sha256', $purpose.'|'.$review->employee_id.'|'.$review->date->toDateString()), 0, 8));
    }
}
