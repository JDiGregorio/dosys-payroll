<?php

namespace App\Services\Trackabi;

use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffImport;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculationService;
use App\Services\ScheduleExpectationService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TrackabiImportService
{
    public function __construct(
        private readonly TrackabiClient $client,
        private readonly TrackabiDailyNormalizer $normalizer,
        private readonly PayrollCalculationService $payrollCalculationService,
        private readonly ScheduleExpectationService $scheduleExpectationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(PayrollPeriod $period, string $campaignName, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->buildPlan($period, $campaignName, $from, $to);
    }

    /**
     * @return array<string, mixed>
     */
    public function import(PayrollPeriod $period, string $campaignName, CarbonInterface $from, CarbonInterface $to): array
    {
        $plan = $this->buildPlan($period, $campaignName, $from, $to);

        DB::transaction(function () use ($period, $campaignName, &$plan): void {
            $import = HubstaffImport::query()->create([
                'payroll_period_id' => $period->id,
                'source_provider' => 'trackabi',
                'original_filename' => sprintf(
                    'trackabi-api:%s:%s:%s',
                    $campaignName,
                    $plan['from'],
                    $plan['to'],
                ),
                'imported_by' => auth()->user()?->email,
                'status' => 'pending',
            ]);

            $created = 0;
            $affectedEmployeeIds = collect();

            foreach ($plan['daily_summaries'] as $summary) {
                $shouldAffectPayroll = ! $summary['protected_review']
                    && ! $summary['manual_overlap_conflict'];

                HubstaffTimeEntry::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $summary['employee_id'])
                    ->whereDate('date', $summary['date'])
                    ->whereIn('source_provider', ['trackabi', 'trackabi_api'])
                    ->update(['active' => false]);

                if ($summary['has_hubstaff_conflict'] && $this->trackabiWinsAfterCutoff() && ! $summary['protected_review']) {
                    HubstaffTimeEntry::query()
                        ->where('payroll_period_id', $period->id)
                        ->where('employee_id', $summary['employee_id'])
                        ->whereDate('date', $summary['date'])
                        ->where('active', true)
                        ->where(function ($query): void {
                            $query->whereNull('source_provider')
                                ->orWhere('source_provider', 'hubstaff_csv');
                        })
                        ->update(['active' => false]);
                }

                foreach ($summary['entries'] as $entry) {
                    HubstaffTimeEntry::query()->create([
                        'payroll_period_id' => $period->id,
                        'hubstaff_import_id' => $import->id,
                        'source_provider' => 'trackabi',
                        'active' => $shouldAffectPayroll,
                        'external_id' => $entry['external_id'],
                        'source_email' => $entry['source_email'],
                        'source_member_id' => $entry['source_member_id'],
                        'source_started_at' => $entry['source_started_at'],
                        'source_ended_at' => $entry['source_ended_at'],
                        'source_time_type' => $entry['time_type'],
                        'employee_id' => $summary['employee_id'],
                        'hubstaff_member' => $entry['member_name'] ?? $entry['source_email'] ?? 'Trackabi sin nombre',
                        'date' => $summary['date'],
                        'project' => $entry['project'] ?? $campaignName,
                        'team' => null,
                        'task_id' => null,
                        'todo' => $entry['task'],
                        'regular_seconds' => $entry['tracked_seconds'],
                        'total_seconds' => $entry['tracked_seconds'],
                        'billable_seconds' => $entry['billable_seconds'],
                        'productive_seconds' => $entry['productive_seconds'],
                        'unproductive_seconds' => $entry['unproductive_seconds'],
                        'activity_score' => $entry['activity_score'],
                        'adjusted_payable_seconds' => $entry['adjusted_payable_seconds'],
                        'adjustment_reason' => $entry['adjustment_reason'],
                        'requires_manual_review' => $entry['requires_manual_review'],
                        'activity_percentage' => $entry['activity_score'],
                        'idle_seconds' => 0,
                        'idle_percentage' => null,
                        'raw_payload' => [
                            'trackabi_payload' => $entry['raw_payload'],
                            'warning_codes' => $entry['warning_codes'],
                            'raw_tracked_seconds' => $entry['raw_tracked_seconds'],
                            'adjusted_tracked_seconds' => $entry['tracked_seconds'],
                            'daily_capped_seconds' => $entry['daily_capped_seconds'],
                            'daily_break_credit_seconds' => $entry['daily_break_credit_seconds'],
                            'daily_estimated_loss_seconds' => $entry['daily_estimated_loss_seconds'],
                        ],
                    ]);

                    $created++;
                }

                if ($shouldAffectPayroll) {
                    $affectedEmployeeIds->push($summary['employee_id']);
                }
            }

            $import->update([
                'rows_count' => $created,
                'status' => 'processed',
            ]);

            $affectedEmployeeIds
                ->unique()
                ->each(function (int $employeeId) use ($period): void {
                    $employee = Employee::query()->find($employeeId);

                    if ($employee) {
                        $this->payrollCalculationService->recalculateEmployeePreservingManual($period, $employee);
                    }
                });

            $plan['created_entries'] = $created;
            $plan['affected_employees'] = $affectedEmployeeIds->unique()->values()->all();
        });

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlan(PayrollPeriod $period, string $campaignName, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($from->lt($period->starts_at) || $to->gt($period->ends_at)) {
            throw new RuntimeException('El rango Trackabi debe estar dentro del período de planilla seleccionado.');
        }

        $campaign = Campaign::query()
            ->whereRaw('lower(name) = ?', [strtolower($campaignName)])
            ->first();

        if (! $campaign) {
            throw new RuntimeException("No existe la campaña {$campaignName} en payroll.");
        }

        $employees = Employee::query()
            ->where('campaign_id', $campaign->id)
            ->where('active', true)
            ->get();
        $allowedEmails = $this->allowedEmails($campaignName);
        $rawEntries = $this->client->listTimeEntries($from, $to, [
            'projectId' => $this->shouldFilterByProjectId()
                ? $this->projectId($campaignName)
                : '',
        ]);
        $normalizedEntries = collect($rawEntries)
            ->map(fn (array $entry): ?array => $this->normalizer->normalizeEntry($entry))
            ->filter()
            ->filter(fn (array $entry): bool => $this->dateInsideRange($entry['date'], $from, $to))
            ->values();
        $loggedTimeNullEntries = $normalizedEntries
            ->filter(fn (array $entry): bool => in_array('logged_time_null', $entry['warning_codes'], true))
            ->values();
        $unmapped = collect();
        $mappedEntries = $normalizedEntries
            ->map(function (array $entry) use ($employees, $campaignName, $unmapped): ?array {
                $employee = $this->resolveEmployee($employees, $entry);

                if (! $employee) {
                    $unmapped->push([
                        'email' => $entry['source_email'],
                        'name' => $entry['member_name'],
                        'date' => $entry['date'],
                    ]);

                    return null;
                }

                $entry['employee_id'] = $employee->id;
                $entry['employee_name'] = $employee->name;
                $entry['employee_email'] = strtolower((string) $employee->email);
                $entry['project'] ??= $campaignName;

                return $entry;
            })
            ->filter()
            ->filter(fn (array $entry): bool => $this->isAllowedEmployee($entry, $allowedEmails))
            ->values();

        $summaries = $mappedEntries
            ->groupBy(fn (array $entry): string => $entry['employee_id'].'|'.$entry['date'])
            ->map(function (Collection $entries, string $key) use ($period): array {
                [$employeeId, $date] = explode('|', $key);
                $employee = Employee::query()->find((int) $employeeId);
                $review = DailyTimeReview::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employeeId)
                    ->whereDate('date', $date)
                    ->first();
                $hasHubstaffConflict = HubstaffTimeEntry::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employeeId)
                    ->whereDate('date', $date)
                    ->where('active', true)
                    ->where(function ($query): void {
                        $query->whereNull('source_provider')
                            ->orWhere('source_provider', 'hubstaff_csv');
                    })
                    ->exists();

                $adjustedEntries = $this->adjustedEntries($entries, $employee, $date);

                return [
                    'employee_id' => (int) $employeeId,
                    'employee_name' => $employee?->name,
                    'date' => $date,
                    'entries_count' => $adjustedEntries->count(),
                    'raw_tracked_seconds' => (int) $entries->sum('tracked_seconds'),
                    'tracked_seconds' => (int) $adjustedEntries->sum('tracked_seconds'),
                    'capped_seconds' => (int) $adjustedEntries->first()['daily_capped_seconds'],
                    'break_credit_seconds' => (int) $adjustedEntries->first()['daily_break_credit_seconds'],
                    'estimated_loss_seconds' => (int) $adjustedEntries->first()['daily_estimated_loss_seconds'],
                    'productive_seconds' => $entries->contains(fn (array $entry): bool => $entry['productive_seconds'] !== null)
                        ? (int) $entries->sum('productive_seconds')
                        : null,
                    'requires_manual_review' => $adjustedEntries->contains('requires_manual_review', true),
                    'has_hubstaff_conflict' => $hasHubstaffConflict,
                    'protected_review' => $this->protectedReview($review),
                    'manual_overlap_conflict' => $hasHubstaffConflict && ! $this->trackabiWinsAfterCutoff(),
                    'entries' => $adjustedEntries->values()->all(),
                ];
            })
            ->values();
        $summaries = $this->distributeEstimatedLossIrregularly($summaries);

        return [
            'period_id' => $period->id,
            'campaign' => $campaignName,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'raw_entries' => count($rawEntries),
            'date_filtered_entries' => $normalizedEntries->count(),
            'normalized_entries' => $normalizedEntries->count(),
            'logged_time_null_entries' => $loggedTimeNullEntries->count(),
            'mapped_entries' => $mappedEntries->count(),
            'employees_found' => $summaries->pluck('employee_id')->unique()->count(),
            'days_found' => $summaries->count(),
            'raw_tracked_seconds' => (int) $summaries->sum('raw_tracked_seconds'),
            'capped_seconds' => (int) $summaries->sum('capped_seconds'),
            'break_credit_seconds' => (int) $summaries->sum('break_credit_seconds'),
            'estimated_loss_seconds' => (int) $summaries->sum('estimated_loss_seconds'),
            'total_tracked_seconds' => (int) $summaries->sum('tracked_seconds'),
            'total_productive_seconds' => $summaries->contains(fn (array $summary): bool => $summary['productive_seconds'] !== null)
                ? (int) $summaries->sum('productive_seconds')
                : null,
            'conflicts' => $summaries->where('has_hubstaff_conflict', true)->values()->all(),
            'manual_reviews' => $summaries->where('requires_manual_review', true)->values()->all(),
            'protected_reviews' => $summaries->where('protected_review', true)->values()->all(),
            'importable_reviews' => $summaries
                ->reject(fn (array $summary): bool => $summary['protected_review'] || $summary['manual_overlap_conflict'])
                ->values()
                ->all(),
            'unmapped' => $unmapped->unique(fn (array $row): string => ($row['email'] ?? '').'|'.($row['name'] ?? ''))->values()->all(),
            'totals_by_employee' => $summaries
                ->groupBy('employee_id')
                ->map(fn (Collection $employeeSummaries): array => [
                    'employee_name' => $employeeSummaries->first()['employee_name'],
                    'raw_tracked_seconds' => (int) $employeeSummaries->sum('raw_tracked_seconds'),
                    'tracked_seconds' => (int) $employeeSummaries->sum('tracked_seconds'),
                    'break_credit_seconds' => (int) $employeeSummaries->sum('break_credit_seconds'),
                    'estimated_loss_seconds' => (int) $employeeSummaries->sum('estimated_loss_seconds'),
                    'days' => $employeeSummaries->count(),
                ])
                ->values()
                ->all(),
            'daily_summaries' => $summaries->all(),
            'created_entries' => 0,
            'affected_employees' => [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedEmails(string $campaignName): array
    {
        if (strtolower($campaignName) !== 'palmetto') {
            return [];
        }

        return collect(config('trackabi.palmetto_emails', []))
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter()
            ->values()
            ->all();
    }

    private function projectId(string $campaignName): ?int
    {
        return strtolower($campaignName) === 'palmetto'
            ? (int) config('trackabi.palmetto_project_id', 75415)
            : null;
    }

    private function shouldFilterByProjectId(): bool
    {
        return filter_var(config('trackabi.filter_by_project_id', false), FILTER_VALIDATE_BOOL);
    }

    private function dateInsideRange(string $date, CarbonInterface $from, CarbonInterface $to): bool
    {
        return $date >= $from->toDateString()
            && $date <= $to->toDateString();
    }

    /**
     * @param  array<int, string>  $allowedEmails
     */
    private function isAllowedEmployee(array $entry, array $allowedEmails): bool
    {
        if ($allowedEmails === []) {
            return true;
        }

        return in_array((string) ($entry['employee_email'] ?? ''), $allowedEmails, true)
            || (
                ($entry['source_email'] ?? null) !== null
                && in_array(strtolower((string) $entry['source_email']), $allowedEmails, true)
            );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function adjustedEntries(Collection $entries, ?Employee $employee, string $date): Collection
    {
        $entries = $entries->values();
        $rawSeconds = (int) $entries->sum('tracked_seconds');

        if (
            ! filter_var(config('trackabi.estimate_real_time', false), FILTER_VALIDATE_BOOL)
            || ! $employee
            || $rawSeconds <= 0
        ) {
            return $this->entriesWithDailyAdjustmentMetadata($entries, $rawSeconds, 0, 0);
        }

        $limitSeconds = $this->dailyLimitSeconds($employee, $date);
        $cappedSeconds = $limitSeconds > 0
            ? min($rawSeconds, $limitSeconds)
            : $rawSeconds;
        $breakCreditSeconds = $this->breakCreditSeconds($rawSeconds, $limitSeconds);
        $targetSeconds = $breakCreditSeconds > 0
            ? min($rawSeconds + $breakCreditSeconds, $limitSeconds)
            : $cappedSeconds;

        if ($targetSeconds === $rawSeconds) {
            return $this->entriesWithDailyAdjustmentMetadata($entries, $cappedSeconds, $breakCreditSeconds, 0);
        }

        return $this->distributeAdjustedSeconds($entries, $targetSeconds, $cappedSeconds, $breakCreditSeconds, 0);
    }

    private function dailyLimitSeconds(Employee $employee, string $date): int
    {
        $expectation = $this->scheduleExpectationService->forDate($employee, Carbon::parse($date));
        $ordinarySeconds = (int) $expectation['expected_ordinary_seconds'];

        if ($ordinarySeconds <= 0) {
            return 0;
        }

        return $ordinarySeconds + $this->dailyPreassignedOvertimeSeconds($employee);
    }

    private function dailyPreassignedOvertimeSeconds(Employee $employee): int
    {
        $weeklyHours = (float) $employee->preassigned_overtime_weekly_hours
            ?: (float) $employee->overtime_hours;

        if ($weeklyHours <= 0) {
            return 0;
        }

        return max(3600, (int) ceil($weeklyHours * 3600 / 5));
    }

    private function breakCreditSeconds(int $rawSeconds, int $limitSeconds): int
    {
        if ($rawSeconds <= 0 || $limitSeconds <= 0 || $rawSeconds >= $limitSeconds) {
            return 0;
        }

        $creditSeconds = max((int) config('trackabi.credited_break_minutes', 75), 0) * 60;

        return min($creditSeconds, $limitSeconds - $rawSeconds);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $summaries
     * @return Collection<int, array<string, mixed>>
     */
    private function distributeEstimatedLossIrregularly(Collection $summaries): Collection
    {
        if (! filter_var(config('trackabi.estimate_real_time', false), FILTER_VALIDATE_BOOL)) {
            return $summaries;
        }

        $updated = $summaries->values();

        $updated
            ->groupBy('employee_id')
            ->each(function (Collection $employeeSummaries) use (&$updated): void {
                $employee = Employee::query()->find((int) $employeeSummaries->first()['employee_id']);

                if (! $employee) {
                    return;
                }

                $dailyLossSeconds = $this->historicalDailyLossSeconds($employee);

                if ($dailyLossSeconds <= 0) {
                    return;
                }

                $eligibleSummaries = $employeeSummaries
                    ->filter(fn (array $summary): bool => (int) $summary['capped_seconds'] > 0
                        && (int) $summary['raw_tracked_seconds'] > (int) $summary['capped_seconds'])
                    ->sortByDesc(fn (array $summary): int => (int) $summary['raw_tracked_seconds'] - (int) $summary['capped_seconds'])
                    ->values();

                if ($eligibleSummaries->isEmpty()) {
                    return;
                }

                $remainingLossSeconds = $dailyLossSeconds * $eligibleSummaries->count();
                $maxDailyLossSeconds = max((int) config('trackabi.estimated_loss_max_minutes', 15), 0) * 60;

                foreach ($eligibleSummaries as $summary) {
                    if ($remainingLossSeconds <= 0) {
                        break;
                    }

                    $lossSeconds = min(
                        $remainingLossSeconds,
                        $maxDailyLossSeconds,
                        (int) $summary['tracked_seconds'],
                    );

                    if ($lossSeconds <= 0) {
                        continue;
                    }

                    $index = $updated->search(fn (array $candidate): bool => (int) $candidate['employee_id'] === (int) $summary['employee_id']
                        && $candidate['date'] === $summary['date']);

                    if ($index === false) {
                        continue;
                    }

                    $updated[$index] = $this->applyEstimatedLossToSummary($summary, $lossSeconds);
                    $remainingLossSeconds -= $lossSeconds;
                }
            });

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function applyEstimatedLossToSummary(array $summary, int $lossSeconds): array
    {
        $entries = collect($summary['entries'])->values();
        $currentSeconds = (int) $entries->sum('tracked_seconds');
        $targetSeconds = max($currentSeconds - $lossSeconds, 0);
        $remainingSeconds = $targetSeconds;
        $lastIndex = $entries->count() - 1;

        $summary['entries'] = $entries->map(function (array $entry, int $index) use ($currentSeconds, $targetSeconds, &$remainingSeconds, $lastIndex, $summary, $lossSeconds): array {
            $adjustedSeconds = $index === $lastIndex
                ? $remainingSeconds
                : min((int) floor($targetSeconds * ((int) $entry['tracked_seconds'] / max($currentSeconds, 1))), $remainingSeconds);
            $remainingSeconds -= $adjustedSeconds;

            $entry['tracked_seconds'] = max($adjustedSeconds, 0);
            $entry['adjusted_payable_seconds'] = max($adjustedSeconds, 0);
            $entry['daily_capped_seconds'] = (int) $summary['capped_seconds'];
            $entry['daily_break_credit_seconds'] = (int) $summary['break_credit_seconds'];
            $entry['daily_estimated_loss_seconds'] = $lossSeconds;
            $entry['adjustment_reason'] = trim(($entry['adjustment_reason'] ? $entry['adjustment_reason'].' ' : '')
                .'Ajuste estimado Palmetto: pérdida histórica distribuida de forma irregular.');

            return $entry;
        })->all();

        $summary['tracked_seconds'] = $targetSeconds;
        $summary['estimated_loss_seconds'] = $lossSeconds;

        return $summary;
    }

    private function historicalDailyLossSeconds(Employee $employee): int
    {
        static $cache = [];

        if (array_key_exists($employee->id, $cache)) {
            return $cache[$employee->id];
        }

        $periodIds = config('trackabi.historical_loss_period_ids', []);
        $losses = [];

        DailyTimeReview::query()
            ->whereIn('payroll_period_id', $periodIds)
            ->where('employee_id', $employee->id)
            ->where('scheduled_work_day', true)
            ->where('hubstaff_total_seconds', '>', 0)
            ->get()
            ->each(function (DailyTimeReview $review) use (&$losses): void {
                $requiredSeconds = max(
                    (int) $review->expected_paid_seconds,
                    (int) $review->expected_ordinary_seconds + (int) $review->preassigned_overtime_seconds,
                );
                $trackedSeconds = (int) $review->hubstaff_total_seconds
                    + (int) $review->paid_time_not_tracked_seconds;
                $lossSeconds = max($requiredSeconds - $trackedSeconds, 0);

                if ($lossSeconds <= 7200) {
                    $losses[] = $lossSeconds;
                }
            });

        if ($losses === []) {
            return $cache[$employee->id] = 0;
        }

        $averageSeconds = array_sum($losses) / count($losses);

        if ($averageSeconds < 60) {
            return $cache[$employee->id] = 0;
        }

        $roundedSeconds = (int) round($averageSeconds / 60) * 60;
        $maxSeconds = max((int) config('trackabi.estimated_loss_max_minutes', 15), 0) * 60;

        return $cache[$employee->id] = min($roundedSeconds, $maxSeconds);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function distributeAdjustedSeconds(Collection $entries, int $targetSeconds, int $cappedSeconds, int $breakCreditSeconds, int $estimatedLossSeconds): Collection
    {
        $rawSeconds = max((int) $entries->sum('tracked_seconds'), 1);
        $remainingSeconds = $targetSeconds;
        $lastIndex = $entries->count() - 1;

        return $entries->values()->map(function (array $entry, int $index) use ($rawSeconds, $targetSeconds, $cappedSeconds, $breakCreditSeconds, $estimatedLossSeconds, &$remainingSeconds, $lastIndex): array {
            $adjustedSeconds = $index === $lastIndex
                ? $remainingSeconds
                : min((int) floor($targetSeconds * ((int) $entry['tracked_seconds'] / $rawSeconds)), $remainingSeconds);
            $remainingSeconds -= $adjustedSeconds;

            $entry['raw_tracked_seconds'] = (int) $entry['tracked_seconds'];
            $entry['tracked_seconds'] = max($adjustedSeconds, 0);
            $entry['adjusted_payable_seconds'] = max($adjustedSeconds, 0);
            $entry['daily_capped_seconds'] = $cappedSeconds;
            $entry['daily_break_credit_seconds'] = $breakCreditSeconds;
            $entry['daily_estimated_loss_seconds'] = $estimatedLossSeconds;

            if ($entry['raw_tracked_seconds'] !== $entry['tracked_seconds']) {
                $reason = $entry['tracked_seconds'] > $entry['raw_tracked_seconds']
                    ? 'Ajuste estimado Palmetto: crédito de almuerzo/break.'
                    : 'Ajuste estimado Palmetto: cap por horario.';
                $entry['adjustment_reason'] = trim(($entry['adjustment_reason'] ? $entry['adjustment_reason'].' ' : '').$reason);
            }

            return $entry;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function entriesWithDailyAdjustmentMetadata(Collection $entries, int $cappedSeconds, int $breakCreditSeconds, int $estimatedLossSeconds): Collection
    {
        return $entries->map(function (array $entry) use ($cappedSeconds, $breakCreditSeconds, $estimatedLossSeconds): array {
            $entry['raw_tracked_seconds'] = (int) $entry['tracked_seconds'];
            $entry['daily_capped_seconds'] = $cappedSeconds;
            $entry['daily_break_credit_seconds'] = $breakCreditSeconds;
            $entry['daily_estimated_loss_seconds'] = $estimatedLossSeconds;

            return $entry;
        });
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $entry
     */
    private function resolveEmployee(Collection $employees, array $entry): ?Employee
    {
        $email = $entry['source_email'];

        if ($email) {
            $employee = $employees->first(fn (Employee $employee): bool => strtolower((string) $employee->email) === $email);

            if ($employee) {
                return $employee;
            }
        }

        $name = $this->normalizeName((string) ($entry['member_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $tokens = collect(explode(' ', $name))->filter()->values();

        return $employees->first(function (Employee $employee) use ($tokens): bool {
            $employeeName = $this->normalizeName($employee->name.' '.$employee->hubstaff_name);

            return $tokens->every(fn (string $token): bool => str_contains($employeeName, $token));
        });
    }

    private function normalizeName(string $name): string
    {
        $name = str_replace(',', ' ', $name);

        return (string) Str::of(Str::ascii($name))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }

    private function protectedReview(?DailyTimeReview $review): bool
    {
        if (! $review) {
            return false;
        }

        return in_array($review->status, [
            'revisado_supervisor',
            'aprobado_rrhh',
            'cerrado',
            'locked',
            'reviewed',
            'approved',
        ], true);
    }

    private function trackabiWinsAfterCutoff(): bool
    {
        return config('trackabi.conflict_strategy') === 'trackabi_wins_after_cutoff';
    }
}
