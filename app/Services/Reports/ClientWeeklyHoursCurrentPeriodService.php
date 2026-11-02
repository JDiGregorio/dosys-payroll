<?php

namespace App\Services\Reports;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Services\ScheduleExpectationService;
use App\Services\Trackabi\TrackabiEmployeeIdentityResolver;
use App\Services\Trackabi\TrackabiInsightsCsvActivityProvider;
use App\Services\Trackabi\TrackabiPaidActivityService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use RuntimeException;

class ClientWeeklyHoursCurrentPeriodService
{
    public function __construct(
        private ClientWeeklyHoursReportService $historical,
        private ScheduleExpectationService $schedule,
        private TrackabiPaidActivityService $paidActivity,
        private TrackabiInsightsCsvActivityProvider $csvProvider,
        private TrackabiEmployeeIdentityResolver $identityResolver,
    ) {}

    public function build(string $csvDirectory): array
    {
        $report = $this->historical->build('2026-10-12', '2026-10-25');
        $period = PayrollPeriod::query()->whereDate('starts_at', '2026-10-11')
            ->whereDate('ends_at', '2026-10-25')->firstOrFail();
        $employees = PayrollResult::query()->where('payroll_period_id', $period->id)
            ->with(['employee.campaign', 'employee.workScheduleTemplate.days',
                'employee.scheduleAssignments.template.days', 'employee.scheduleType'])
            ->get()->pluck('employee')->filter()
            ->reject(fn (Employee $employee): bool => mb_strtoupper(trim((string) $employee->campaign?->name)) === 'DOSYS BPO'
                || mb_strtolower(trim($employee->name)) === 'jose di gregorio')
            ->keyBy('id');
        $recentReviews = DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->whereBetween('date', ['2026-10-12', '2026-10-25'])->get()->groupBy('employee_id');
        $activity = $this->readCsvWeek($csvDirectory, $employees, $report['warnings']);
        $weekStart = Carbon::parse('2026-10-26');
        $weekEnd = Carbon::parse('2026-11-01');
        $projectedDays = [];

        foreach ($employees as $employee) {
            $weeklyOtRemaining = max((int) round((float) $employee->preassigned_overtime_weekly_hours * 3600), 0);
            foreach (CarbonPeriod::create($weekStart, $weekEnd) as $date) {
                $dateString = $date->toDateString();
                $measured = $activity[$employee->id][$dateString] ?? null;
                if ($measured === null) {
                    $report['warnings'][] = $this->warning('MISSING_INSIGHTS_ROW', $employee, $dateString,
                        'No hay fila de Insights para este empleado; no se asumió actividad cero.');
                    continue;
                }
                $schedule = $this->schedule->forDate($employee, $date);
                $prior = $recentReviews->get($employee->id, collect())
                    ->filter(fn (DailyTimeReview $review): bool => $review->date->dayOfWeekIso === $date->dayOfWeekIso);
                $repeatedOff = $prior->count() === 2 && $prior->every(fn (DailyTimeReview $review): bool => $review->paid_day_off);
                $repeatedWork = $prior->count() === 2 && $prior->every(fn (DailyTimeReview $review): bool =>
                    ! $review->paid_day_off && (int) $review->expected_ordinary_seconds > 0);
                $off = $measured === 0 && (! $schedule['scheduled_work_day'] || $repeatedOff);
                $regular = $off ? 0 : (int) $schedule['expected_ordinary_seconds'];
                if ($regular === 0 && $measured > 0 && $repeatedWork) {
                    $regular = (int) $prior->sortByDesc('date')->first()->expected_ordinary_seconds;
                }
                $working = $regular > 0 && $measured > 0;
                $overtime = 0;
                if ($working && $weeklyOtRemaining > 0) {
                    if ($employee->overtime_distribution_mode === 'weekly_flexible') {
                        $overtime = min($weeklyOtRemaining, max($measured - $regular, 0));
                    } elseif ($schedule['schedule_type'] === 'rotativa') {
                        $overtime = min($weeklyOtRemaining, 3600);
                    } else {
                        $overtime = min($weeklyOtRemaining,
                            (int) round((float) $employee->preassigned_overtime_weekly_hours * 3600 / 5));
                    }
                } elseif ($measured > 0 && $regular === 0 && $employee->overtime_distribution_mode === 'weekly_flexible') {
                    $overtime = min($weeklyOtRemaining, $measured);
                } elseif ($measured > 0 && $regular === 0) {
                    $report['warnings'][] = $this->warning('WORK_ON_UNSCHEDULED_DAY', $employee, $dateString,
                        'Hay actividad en día sin jornada ordinaria; falta autorización para facturarla.');
                }
                $weeklyOtRemaining -= $overtime;
                $base = $regular + $overtime;
                $allowance = $working ? min($base,
                    array_sum(array_intersect_key($this->paidActivity->paidTimePolicy($employee),
                        array_flip(['lunch_minutes', 'break_minutes']))) * 60) : 0;
                $gap = $base > 0 && $measured > 0 ? max($base - $allowance - $measured, 0) : $base;
                $calculated = $this->historical->calculateDay([
                    'ordinary_seconds' => $regular,
                    'overtime_seconds' => $overtime,
                    'remaining_gap_seconds' => $gap,
                    'non_billable' => $off || $measured === 0,
                    'non_billable_rule' => $off ? 'SCHEDULED_OFF' : 'NO_ACTIVITY',
                ]);
                $projectedDays[] = [
                    'week_start' => '2026-10-26', 'week_end' => '2026-11-01',
                    'campaign' => $employee->campaign?->name ?? 'SIN CAMPAÑA',
                    'employee_id' => $employee->id, 'employee' => $employee->name, 'date' => $dateString,
                    'expected_regular_seconds' => $regular, 'approved_ot_seconds' => $overtime,
                    'base_seconds' => $base, 'expected_trackable_seconds' => max($base - $allowance, 0),
                    'activity_source' => 'trackabi_insights_csv_read_only', 'desktop_activity_seconds' => $measured,
                    'justified_seconds' => 0, 'remaining_gap_seconds' => $gap,
                    'rule' => $calculated['rule'], 'billable_seconds' => $calculated['billable_seconds'],
                    'comment' => $repeatedOff && $off ? 'OFF repetido en las dos semanas previas'
                        : ($repeatedOff && $measured > 0 ? 'Actividad en OFF habitual; sujeta al tope semanal'
                            : ($repeatedWork && ! $schedule['scheduled_work_day']
                                ? 'Jornada respaldada por los dos días equivalentes anteriores' : '')),
                    'historical_off_with_activity' => $repeatedOff && $measured > 0,
                    'expected_weekly_regular_seconds' => (int) round((float) ($employee->ordinary_weekly_hours ?: $employee->weekly_hours) * 3600),
                    'expected_weekly_ot_seconds' => (int) round((float) $employee->preassigned_overtime_weekly_hours * 3600),
                    'review_status' => 'projection_read_only', 'schedule_name' => $employee->workScheduleTemplate?->name,
                    'active_now' => (bool) $employee->active,
                ];
            }
        }

        foreach (collect($projectedDays)->groupBy('employee_id') as $employeeDays) {
            $first = $employeeDays->first();
            $limit = $first['expected_weekly_regular_seconds'] + $first['expected_weekly_ot_seconds'];
            $excess = $limit > 0 ? max((int) $employeeDays->sum('billable_seconds') - $limit, 0) : 0;
            if ($excess === 0) {
                continue;
            }
            $candidates = $employeeDays->sort(function (array $left, array $right): int {
                $priority = (int) $right['historical_off_with_activity'] <=> (int) $left['historical_off_with_activity'];

                return $priority !== 0 ? $priority : strcmp($right['date'], $left['date']);
            });
            foreach ($candidates as $candidate) {
                if ($excess === 0) {
                    break;
                }
                $index = collect($projectedDays)->search(fn (array $day): bool =>
                    $day['employee_id'] === $candidate['employee_id'] && $day['date'] === $candidate['date']);
                $reduction = min($excess, $projectedDays[$index]['billable_seconds']);
                if ($reduction === 0) {
                    continue;
                }
                $projectedDays[$index]['billable_seconds'] -= $reduction;
                $projectedDays[$index]['rule'] = 'WEEKLY_CAP_APPLIED';
                $projectedDays[$index]['comment'] = trim($projectedDays[$index]['comment'].' | Tope semanal: '
                    .ClientWeeklyHoursExporter::hours($reduction).' no facturables', ' |');
                $excess -= $reduction;
            }
        }
        $byEmployee = collect($projectedDays)->groupBy('employee_id');
        $members = $byEmployee->map(function (Collection $days): array {
            $first = $days->first();
            $daily = $days->pluck('billable_seconds', 'date')->all();

            return [
                'employee_id' => $first['employee_id'], 'employee' => $first['employee'],
                'campaign' => $first['campaign'], 'daily' => $daily,
                'total_seconds' => array_sum($daily),
                'expected_weekly_regular_seconds' => $first['expected_weekly_regular_seconds'],
                'expected_weekly_ot_seconds' => $first['expected_weekly_ot_seconds'],
            ];
        })->values()->sortBy(fn (array $member): string => $member['campaign'].'|'.$member['employee'])->values()->all();
        foreach ($members as $member) {
            $expected = $member['expected_weekly_regular_seconds'] + $member['expected_weekly_ot_seconds'];
            if ($expected > 0 && $member['total_seconds'] > $expected + 60) {
                $report['warnings'][] = [
                    'code' => 'WEEKLY_OVER_TARGET', 'employee' => $member['employee'], 'date' => '2026-10-26',
                    'message' => 'Horas facturables mayores que la expectativa semanal configurada.',
                ];
            }
        }
        $report['to'] = '2026-11-01';
        $report['weeks'][] = ['start' => '2026-10-26', 'end' => '2026-11-01',
            'members' => $members, 'total_seconds' => array_sum(array_column($members, 'total_seconds'))];
        $report['days'] = array_merge($report['days'], $projectedDays);
        $report['campaigns'] = collect($report['days'])->groupBy('campaign')->map(fn (Collection $days, string $name): array => [
            'name' => $name, 'employees' => $days->pluck('employee_id')->unique()->count(),
            'total_seconds' => (int) $days->sum('billable_seconds'),
        ])->sortKeys()->values()->all();
        $report['employee_count'] = collect($report['days'])->pluck('employee_id')->unique()->count();
        $report['employee_weeks'] = array_sum(array_map(fn (array $week): int => count($week['members']), $report['weeks']));
        $report['grand_total_seconds'] = (int) collect($report['days'])->sum('billable_seconds');
        $advisory = ['UNRESOLVED_REVIEW', 'MISSING_WEEKLY_CONFIGURATION', 'AMBIGUOUS_LEAVE_OR_HOLIDAY'];
        $report['blocking_warnings'] = array_values(array_filter($report['warnings'],
            fn (array $warning): bool => ! in_array($warning['code'], $advisory, true)));
        $report['safe_to_send_client'] = $report['blocking_warnings'] === [];

        return $report;
    }

    private function readCsvWeek(string $directory, Collection $employees, array &$warnings): array
    {
        $activity = [];
        foreach (CarbonPeriod::create('2026-10-26', '2026-11-01') as $date) {
            $day = $date->toDateString();
            $path = rtrim($directory, '/').'/'.$day.'-'.$day.'-insights.csv';
            if (! is_readable($path)) {
                throw new RuntimeException("Falta CSV de Insights: {$path}");
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException("No se pudo abrir: {$path}");
            }
            try {
                if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
                $header = fgetcsv($handle, 0, ',', '"', '');
                if ($header !== ['Member Name', 'Total logged', 'Total billable',
                    'Productive activity time', 'Unproductive activity time']) {
                    throw new RuntimeException("Encabezados inesperados en {$path}");
                }
                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    if (count($row) !== 5) {
                        throw new RuntimeException("Fila inválida en {$path}");
                    }
                    $employee = $this->identityResolver->resolve($employees, null, null, trim($row[0]));
                    if (! $employee) {
                        if (! in_array(trim($row[0]), config('trackabi_direct.insights_ignored_names', []), true)) {
                            $warnings[] = ['code' => 'UNMAPPED_INSIGHTS_MEMBER', 'employee' => $row[0],
                                'date' => $day, 'message' => 'Nombre de Insights sin empleado de la planilla anterior.'];
                        }
                        continue;
                    }
                    if (isset($activity[$employee->id][$day])) {
                        throw new RuntimeException("Empleado duplicado en CSV {$path}: {$employee->name}");
                    }
                    $normalized = $this->csvProvider->normalize(['productive' => $row[3], 'unproductive' => $row[4]]);
                    $activity[$employee->id][$day] = $normalized->measuredSeconds();
                }
            } finally {
                fclose($handle);
            }
        }

        return $activity;
    }

    private function warning(string $code, Employee $employee, string $date, string $message): array
    {
        return ['code' => $code, 'employee' => $employee->name, 'date' => $date, 'message' => $message];
    }
}
