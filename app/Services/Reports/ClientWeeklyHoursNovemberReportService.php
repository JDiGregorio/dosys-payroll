<?php

namespace App\Services\Reports;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Services\ScheduleExpectationService;
use App\Services\Trackabi\TrackabiPaidActivityService;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class ClientWeeklyHoursNovemberReportService
{
    public function __construct(
        private ClientWeeklyHoursReportService $historical,
        private ClientWeeklyHoursCurrentPeriodService $currentPeriod,
        private ScheduleExpectationService $schedule,
        private TrackabiPaidActivityService $paidActivity,
    ) {}

    public function build(string $csvDirectory): array
    {
        $report = $this->historical->build('2026-11-02', '2026-11-10');
        $period = PayrollPeriod::query()->whereDate('starts_at', '2026-10-26')
            ->whereDate('ends_at', '2026-11-10')->firstOrFail();
        $employees = PayrollResult::query()->where('payroll_period_id', $period->id)
            ->with(['employee.campaign', 'employee.workScheduleTemplate.days',
                'employee.scheduleAssignments.template.days', 'employee.scheduleType'])
            ->get()->pluck('employee')->filter()
            ->reject(fn (Employee $employee): bool => mb_strtoupper(trim((string) $employee->campaign?->name)) === 'DOSYS BPO'
                || mb_strtolower(trim($employee->name)) === 'jose di gregorio')
            ->keyBy('id');
        $recentReviews = DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->whereBetween('date', ['2026-10-28', '2026-11-10'])->get()->groupBy('employee_id');
        $activity = $this->currentPeriod->readCsvRange($csvDirectory, $employees,
            '2026-11-11', '2026-11-15', $report['warnings']);
        $projectedDays = [];
        $priorDays = collect($report['days'])->where('week_start', '2026-11-09')->groupBy('employee_id');

        foreach ($employees as $employee) {
            $weeklyOtRemaining = max((int) round((float) $employee->preassigned_overtime_weekly_hours * 3600), 0)
                - (int) $priorDays->get($employee->id, collect())->sum('approved_ot_seconds');
            $weeklyOtRemaining = max($weeklyOtRemaining, 0);
            foreach (CarbonPeriod::create('2026-11-11', '2026-11-15') as $date) {
                $day = $date->toDateString();
                $measured = $activity[$employee->id][$day] ?? null;
                $confirmedOff = $employee->name === 'Denisha Marissa Buckley Medina'
                    && in_array($day, ['2026-11-11', '2026-11-12'], true);
                $confirmedWork = $employee->name === 'Denisha Marissa Buckley Medina' && $day === '2026-11-13';
                $approvedSaturday = $employee->name === 'Elalf Shamir Dominguez Pineda'
                    && $day === '2026-11-14';
                if ($measured === null && ($confirmedOff || $confirmedWork)) {
                    $measured = 0;
                }
                if ($measured === null) {
                    $report['warnings'][] = $this->warning('MISSING_INSIGHTS_ROW', $employee, $day,
                        'No hay fila de Insights; no se asumió actividad cero.');
                    continue;
                }
                $schedule = $this->schedule->forDate($employee, $date);
                $prior = $recentReviews->get($employee->id, collect())
                    ->filter(fn (DailyTimeReview $review): bool => $review->date->dayOfWeekIso === $date->dayOfWeekIso);
                $repeatedOff = $prior->count() === 2 && $prior->every(fn (DailyTimeReview $review): bool => $review->paid_day_off);
                $repeatedWork = $prior->count() === 2 && $prior->every(fn (DailyTimeReview $review): bool =>
                    ! $review->paid_day_off && (int) $review->expected_ordinary_seconds > 0);
                $off = $confirmedOff || ($measured === 0 && (! $schedule['scheduled_work_day'] || $repeatedOff));
                $regular = $off ? 0 : (int) $schedule['expected_ordinary_seconds'];
                if ($approvedSaturday && $measured > 0) {
                    $regular = 8 * 3600;
                }
                if ($confirmedWork) {
                    $regular = 11 * 3600;
                }
                if ($regular === 0 && $measured > 0 && $repeatedWork) {
                    $regular = (int) $prior->sortByDesc('date')->first()->expected_ordinary_seconds;
                }
                $working = $regular > 0 && $measured > 0;
                $overtime = 0;
                if ($confirmedWork) {
                    $overtime = min($weeklyOtRemaining, 3600);
                } elseif ($working && $weeklyOtRemaining > 0) {
                    if ($employee->overtime_distribution_mode === 'weekly_flexible') {
                        $dailyOtLimit = (int) round((float) $employee->preassigned_overtime_weekly_hours * 3600 / 5);
                        $overtime = min($weeklyOtRemaining, $dailyOtLimit,
                            $this->roundUpToHalfHour(max($measured - $regular, 0)));
                    } elseif ($schedule['schedule_type'] === 'rotativa') {
                        $overtime = min($weeklyOtRemaining, 3600);
                    } else {
                        $overtime = min($weeklyOtRemaining,
                            (int) round((float) $employee->preassigned_overtime_weekly_hours * 3600 / 5));
                    }
                } elseif ($measured > 0 && $regular === 0 && $employee->overtime_distribution_mode === 'weekly_flexible') {
                    $overtime = min($weeklyOtRemaining, $measured);
                } elseif ($measured > 0 && $regular === 0) {
                    $report['warnings'][] = $this->warning('WORK_ON_UNSCHEDULED_DAY', $employee, $day,
                        'Hay actividad en día sin jornada ordinaria; falta autorización para facturarla.');
                }
                $weeklyOtRemaining -= $overtime;
                $base = $regular + $overtime;
                $allowance = $working ? min($base,
                    array_sum(array_intersect_key($this->paidActivity->paidTimePolicy($employee),
                        array_flip(['lunch_minutes', 'break_minutes']))) * 60) : 0;
                $gap = $base > 0 && $measured > 0 ? max($base - $allowance - $measured, 0) : $base;
                $calculated = $this->historical->calculateDay([
                    'ordinary_seconds' => $regular, 'overtime_seconds' => $overtime,
                    'remaining_gap_seconds' => $gap,
                    'non_billable' => $off || $measured === 0,
                    'non_billable_rule' => $off ? 'SCHEDULED_OFF' : 'NO_ACTIVITY',
                ]);
                $unadjustedBillable = $calculated['billable_seconds'];
                if ($confirmedWork) {
                    $calculated['billable_seconds'] = $base;
                    $calculated['remaining_gap_seconds'] = 0;
                    $calculated['rule'] = 'CLIENT_CONFIRMED_WORK';
                }
                $comment = match (true) {
                    $confirmedWork => 'Jornada de 12 h confirmada para reporte; actividad Insights conservada',
                    $confirmedOff => 'OFF confirmado por supervisión',
                    $approvedSaturday => 'Sábado trabajado autorizado: 8 h ordinarias y OT preasignada',
                    $repeatedOff && $off => 'OFF repetido en las dos semanas previas',
                    $repeatedOff && $measured > 0 => 'Actividad en OFF habitual; sujeta al tope semanal',
                    $repeatedWork && ! $schedule['scheduled_work_day'] => 'Jornada respaldada por los dos días equivalentes anteriores',
                    default => '',
                };
                $projectedDays[] = [
                    'week_start' => '2026-11-09', 'week_end' => '2026-11-15',
                    'campaign' => $employee->campaign?->name ?? 'SIN CAMPAÑA',
                    'employee_id' => $employee->id, 'employee' => $employee->name, 'date' => $day,
                    'expected_regular_seconds' => $regular, 'approved_ot_seconds' => $overtime,
                    'base_seconds' => $base, 'expected_trackable_seconds' => max($base - $allowance, 0),
                    'activity_source' => $confirmedWork ? 'client_manual_confirmation_with_insights' : 'trackabi_insights_csv_read_only',
                    'desktop_activity_seconds' => $measured,
                    'justified_seconds' => 0, 'remaining_gap_seconds' => $gap,
                    'rule' => $calculated['rule'], 'billable_seconds' => $calculated['billable_seconds'],
                    'raw_billable_seconds' => $unadjustedBillable,
                    'comment' => $comment,
                    'historical_off_with_activity' => $repeatedOff && $measured > 0,
                    'expected_weekly_regular_seconds' => (int) round((float) ($employee->ordinary_weekly_hours ?: $employee->weekly_hours) * 3600),
                    'expected_weekly_ot_seconds' => (int) round((float) $employee->preassigned_overtime_weekly_hours * 3600),
                    'review_status' => 'projection_read_only', 'schedule_name' => $employee->workScheduleTemplate?->name,
                    'active_now' => (bool) $employee->active,
                ];
            }
        }

        $report['to'] = '2026-11-15';
        $report['days'] = array_merge($report['days'], $projectedDays);
        $this->adjustClientHours($report['days']);
        $report['weeks'] = [];
        foreach ([['2026-11-02', '2026-11-08'], ['2026-11-09', '2026-11-15']] as [$weekStart, $weekEnd]) {
            $weekDays = collect($report['days'])->where('week_start', $weekStart);
            $members = $weekDays->groupBy('employee_id')->map(function (Collection $days) use ($weekStart, $weekEnd): array {
                $first = $days->first();
                $byDate = $days->keyBy('date');
                $daily = [];
                foreach (CarbonPeriod::create($weekStart, $weekEnd) as $date) {
                    $daily[$date->toDateString()] = $byDate->get($date->toDateString())['billable_seconds'] ?? null;
                }

                return [
                    'employee_id' => $first['employee_id'], 'employee' => $first['employee'],
                    'campaign' => $first['campaign'], 'daily' => $daily,
                    'total_seconds' => array_sum(array_filter($daily, fn ($seconds): bool => $seconds !== null)),
                    'expected_weekly_regular_seconds' => $first['expected_weekly_regular_seconds'],
                    'expected_weekly_ot_seconds' => $first['expected_weekly_ot_seconds'],
                ];
            })->values()->sortBy(fn (array $member): string => $member['campaign'].'|'.$member['employee'])->values()->all();
            $report['weeks'][] = ['start' => $weekStart, 'end' => $weekEnd, 'members' => $members,
                'total_seconds' => array_sum(array_column($members, 'total_seconds'))];
        }
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

    private function adjustClientHours(array &$days): void
    {
        foreach ($days as &$day) {
            $original = (int) $day['billable_seconds'];
            $base = max((int) $day['base_seconds'], 0);
            if ($original === 0 || $base === 0) {
                continue;
            }
            $reason = null;
            if ($day['employee'] === 'Sandro Kluivert Aplicano Flores'
                && (int) ($day['desktop_activity_seconds'] ?? 0) > 0) {
                $day['billable_seconds'] = $base;
                $reason = 'Jornada completa confirmada para Sandro';
            } elseif ($day['employee'] === 'Marco Antonio Lara'
                && (int) $day['expected_regular_seconds'] >= 8 * 3600 && $original < 8 * 3600) {
                $day['billable_seconds'] = min(8 * 3600, $base);
                $reason = 'Mínimo de 8 h en jornada regular de Marco';
            } else {
                $day['billable_seconds'] = min($this->roundUpToHalfHour($original), $base);
                if ($day['billable_seconds'] !== $original) {
                    $reason = 'Redondeo a media hora u hora, limitado a la jornada esperada';
                }
            }
            if ($day['billable_seconds'] !== $original) {
                $day['raw_billable_seconds'] ??= $original;
                $day['rule'] = 'CLIENT_REPORT_ADJUSTMENT';
                $day['comment'] = trim($day['comment'].' | '.$reason, ' |');
            }
        }
        unset($day);

        foreach (collect($days)->groupBy(fn (array $day): string => $day['week_start'].'|'.$day['employee_id']) as $employeeDays) {
            $first = $employeeDays->first();
            $limit = $first['expected_weekly_regular_seconds'] + $first['expected_weekly_ot_seconds'];
            $excess = $limit > 0 ? max((int) $employeeDays->sum('billable_seconds') - $limit, 0) : 0;
            if ($excess === 0) {
                continue;
            }
            $candidates = $employeeDays->sort(function (array $left, array $right): int {
                $projected = (int) ($right['review_status'] === 'projection_read_only')
                    <=> (int) ($left['review_status'] === 'projection_read_only');
                $off = (int) ($right['historical_off_with_activity'] ?? false)
                    <=> (int) ($left['historical_off_with_activity'] ?? false);

                return $projected !== 0 ? $projected : ($off !== 0 ? $off : strcmp($right['date'], $left['date']));
            });
            foreach ($candidates as $candidate) {
                if ($excess === 0) {
                    break;
                }
                $index = collect($days)->search(fn (array $day): bool =>
                    $day['employee_id'] === $candidate['employee_id'] && $day['date'] === $candidate['date']);
                $reduction = min($excess, $days[$index]['billable_seconds']);
                if ($reduction === 0) {
                    continue;
                }
                $days[$index]['raw_billable_seconds'] ??= $days[$index]['billable_seconds'];
                $days[$index]['billable_seconds'] -= $reduction;
                $days[$index]['rule'] = 'WEEKLY_CAP_APPLIED';
                $days[$index]['comment'] = trim($days[$index]['comment'].' | Tope semanal: '
                    .ClientWeeklyHoursExporter::hours($reduction).' no facturables', ' |');
                $excess -= $reduction;
            }
        }
    }

    private function roundUpToHalfHour(int $seconds): int
    {
        $remainder = $seconds % 3600;

        return $remainder === 0 ? $seconds
            : intdiv($seconds, 3600) * 3600 + ($remainder <= 1800 ? 1800 : 3600);
    }

    private function warning(string $code, Employee $employee, string $date, string $message): array
    {
        return ['code' => $code, 'employee' => $employee->name, 'date' => $date, 'message' => $message];
    }
}
