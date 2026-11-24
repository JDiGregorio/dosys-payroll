<?php

namespace App\Services\Reports;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Services\ScheduleExpectationService;
use App\Services\Trackabi\TrackabiPaidActivityService;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class ClientWeeklyHoursNovemberThirdWeekService
{
    public function __construct(
        private ClientWeeklyHoursNovemberReportService $previousReport,
        private ClientWeeklyHoursCurrentPeriodService $csvReader,
        private ClientWeeklyHoursReportService $calculator,
        private ScheduleExpectationService $schedule,
        private TrackabiPaidActivityService $paidActivity,
    ) {}

    /** @param array<string, array{regular: int, overtime: int}> $approvedWorkDays */
    public function build(string $previousCsvDirectory, string $csvDirectory, array $approvedWorkDays = [], bool $fullShiftSandro = false): array
    {
        $previous = $this->previousReport->build($previousCsvDirectory);
        $priorDays = collect($previous['days'])->keyBy(fn (array $day): string => $day['employee_id'].'|'.$day['date']);
        $period = PayrollPeriod::query()->whereDate('starts_at', '2026-10-26')
            ->whereDate('ends_at', '2026-11-10')->firstOrFail();
        $employees = PayrollResult::query()->where('payroll_period_id', $period->id)
            ->with(['employee.campaign', 'employee.workScheduleTemplate.days',
                'employee.scheduleAssignments.template.days', 'employee.scheduleType'])
            ->get()->pluck('employee')->filter()
            ->reject(fn (Employee $employee): bool => mb_strtoupper(trim((string) $employee->campaign?->name)) === 'DOSYS BPO'
                || mb_strtolower(trim($employee->name)) === 'jose di gregorio')
            ->keyBy('id');
        $warnings = [];
        $activity = $this->csvReader->readCsvRange($csvDirectory, $employees,
            '2026-11-16', '2026-11-22', $warnings);
        $days = [];

        foreach ($employees as $employee) {
            $weeklyOtRemaining = max((int) round((float) $employee->preassigned_overtime_weekly_hours * 3600), 0);
            foreach (CarbonPeriod::create('2026-11-16', '2026-11-22') as $date) {
                $day = $date->toDateString();
                $measured = $activity[$employee->id][$day] ?? null;
                if ($measured === null) {
                    $warnings[] = $this->warning('MISSING_INSIGHTS_ROW', $employee, $day,
                        'No hay fila de Insights; no se asumió actividad cero.');
                    continue;
                }
                $schedule = $this->schedule->forDate($employee, $date);
                $lastWeek = $priorDays->get($employee->id.'|'.$date->copy()->subWeek()->toDateString());
                $twoWeeksAgo = $priorDays->get($employee->id.'|'.$date->copy()->subWeeks(2)->toDateString());
                $repeatedOff = $lastWeek && $twoWeeksAgo
                    && $lastWeek['rule'] === 'SCHEDULED_OFF' && $twoWeeksAgo['rule'] === 'SCHEDULED_OFF';
                $repeatedWork = $lastWeek && $twoWeeksAgo
                    && $lastWeek['expected_regular_seconds'] > 0 && $twoWeeksAgo['expected_regular_seconds'] > 0
                    && $lastWeek['rule'] !== 'SCHEDULED_OFF' && $twoWeeksAgo['rule'] !== 'SCHEDULED_OFF';
                $approved = $approvedWorkDays[$employee->name.'|'.$day] ?? null;
                $off = $measured === 0 && (! $schedule['scheduled_work_day'] || $repeatedOff);
                $regular = $off ? 0 : (int) $schedule['expected_ordinary_seconds'];
                if ($regular === 0 && $measured > 0 && $repeatedWork && $schedule['schedule_type'] !== 'rotativa') {
                    $regular = (int) $lastWeek['expected_regular_seconds'];
                }
                if ($approved && $measured > 0) {
                    $regular = max((int) $approved['regular'], 0);
                }
                $working = $regular > 0 && $measured > 0;
                $overtime = 0;
                if ($approved && $working) {
                    $overtime = min($weeklyOtRemaining, max((int) $approved['overtime'], 0));
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
                    $overtime = min($weeklyOtRemaining, $this->roundUpToHalfHour($measured));
                } elseif ($measured > 0 && $regular === 0) {
                    $warnings[] = $this->warning('WORK_ON_UNSCHEDULED_DAY', $employee, $day,
                        'Hay actividad en día sin jornada ordinaria; falta autorización para facturarla.');
                }
                $weeklyOtRemaining -= $overtime;
                $base = $regular + $overtime;
                $allowance = $working ? min($base,
                    array_sum(array_intersect_key($this->paidActivity->paidTimePolicy($employee),
                        array_flip(['lunch_minutes', 'break_minutes']))) * 60) : 0;
                $gap = $base > 0 && $measured > 0 ? max($base - $allowance - $measured, 0) : $base;
                $calculated = $this->calculator->calculateDay([
                    'ordinary_seconds' => $regular, 'overtime_seconds' => $overtime,
                    'remaining_gap_seconds' => $gap,
                    'non_billable' => $off || $measured === 0,
                    'non_billable_rule' => $off ? 'SCHEDULED_OFF' : 'NO_ACTIVITY',
                ]);
                $rawBillable = $calculated['billable_seconds'];
                $comment = $approved && $working ? 'Jornada autorizada para facturación'
                    : ($repeatedOff && $off ? 'OFF repetido en las dos semanas previas'
                        : ($repeatedWork && ! $schedule['scheduled_work_day']
                            ? 'Jornada respaldada por los dos días equivalentes anteriores' : ''));
                if ($fullShiftSandro && $employee->name === 'Sandro Kluivert Aplicano Flores' && $measured > 0 && $regular > 0) {
                    $calculated['billable_seconds'] = $base;
                    $calculated['rule'] = 'CLIENT_CONFIRMED_FULL_SHIFT';
                    $comment = trim($comment.' | Jornada completa confirmada para Sandro', ' |');
                }
                if ($employee->name === 'Marco Antonio Lara' && $regular >= 8 * 3600
                    && $measured > 0 && $calculated['billable_seconds'] < 8 * 3600) {
                    $calculated['billable_seconds'] = min(8 * 3600, $base);
                    $calculated['rule'] = 'CLIENT_REPORT_ADJUSTMENT';
                    $comment = trim($comment.' | Mínimo de 8 h en jornada regular de Marco', ' |');
                }
                if ($calculated['billable_seconds'] > 0) {
                    $rounded = min($this->roundUpToHalfHour($calculated['billable_seconds']), $base);
                    if ($rounded !== $calculated['billable_seconds']) {
                        $calculated['billable_seconds'] = $rounded;
                        $calculated['rule'] = 'CLIENT_REPORT_ADJUSTMENT';
                        $comment = trim($comment.' | Redondeo limitado a jornada esperada', ' |');
                    }
                }
                $days[] = [
                    'week_start' => '2026-11-16', 'week_end' => '2026-11-22',
                    'campaign' => $employee->campaign?->name ?? 'SIN CAMPAÑA',
                    'employee_id' => $employee->id, 'employee' => $employee->name, 'date' => $day,
                    'expected_regular_seconds' => $regular, 'approved_ot_seconds' => $overtime,
                    'base_seconds' => $base, 'expected_trackable_seconds' => max($base - $allowance, 0),
                    'activity_source' => 'trackabi_insights_csv_read_only', 'desktop_activity_seconds' => $measured,
                    'justified_seconds' => 0, 'remaining_gap_seconds' => $gap,
                    'rule' => $calculated['rule'], 'billable_seconds' => $calculated['billable_seconds'],
                    'raw_billable_seconds' => $rawBillable, 'comment' => $comment,
                    'historical_off_with_activity' => $repeatedOff && $measured > 0,
                    'expected_weekly_regular_seconds' => (int) round((float) ($employee->ordinary_weekly_hours ?: $employee->weekly_hours) * 3600),
                    'expected_weekly_ot_seconds' => (int) round((float) $employee->preassigned_overtime_weekly_hours * 3600),
                    'review_status' => 'projection_read_only', 'schedule_name' => $employee->workScheduleTemplate?->name,
                    'active_now' => (bool) $employee->active,
                ];
            }
        }

        foreach (collect($days)->groupBy('employee_id') as $employeeDays) {
            $first = $employeeDays->first();
            if ($first['expected_weekly_regular_seconds'] === 0
                && $employeeDays->contains(fn (array $day): bool => $day['expected_regular_seconds'] > 0)) {
                $employee = $employees->get($first['employee_id']);
                $warnings[] = $this->warning('MISSING_WEEKLY_CONFIGURATION', $employee, '2026-11-16',
                    'Hay jornada diaria, pero no expectativa semanal ordinaria configurada.');
            }
        }
        $this->capWeeklyHours($days, $fullShiftSandro);
        if ($fullShiftSandro && collect($days)->where('employee', 'Sandro Kluivert Aplicano Flores')
            ->where('billable_seconds', '>', 0)->count() === 6) {
            $warnings[] = [
                'code' => 'APPROVED_WEEKLY_EXCEPTION', 'employee' => 'Sandro Kluivert Aplicano Flores',
                'date' => '2026-11-21', 'message' => 'Seis jornadas completas autorizadas: 48 h facturables esta semana.',
            ];
        }
        $dayCollection = collect($days);
        $members = $dayCollection->groupBy('employee_id')->map(function (Collection $employeeDays): array {
            $first = $employeeDays->first();
            $byDate = $employeeDays->keyBy('date');
            $daily = [];
            foreach (CarbonPeriod::create('2026-11-16', '2026-11-22') as $date) {
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
        $campaigns = $dayCollection->groupBy('campaign')->map(fn (Collection $campaignDays, string $name): array => [
            'name' => $name, 'employees' => $campaignDays->pluck('employee_id')->unique()->count(),
            'total_seconds' => (int) $campaignDays->sum('billable_seconds'),
        ])->sortKeys()->values()->all();
        $advisory = ['MISSING_WEEKLY_CONFIGURATION', 'APPROVED_WEEKLY_EXCEPTION'];
        $blocking = array_values(array_filter($warnings, fn (array $warning): bool =>
            ! in_array($warning['code'], $advisory, true)));

        return [
            'from' => '2026-11-16', 'to' => '2026-11-22', 'days' => $days,
            'weeks' => [['start' => '2026-11-16', 'end' => '2026-11-22', 'members' => $members,
                'total_seconds' => array_sum(array_column($members, 'total_seconds'))]],
            'campaigns' => $campaigns, 'warnings' => $warnings, 'blocking_warnings' => $blocking,
            'excluded' => [], 'employee_count' => $dayCollection->pluck('employee_id')->unique()->count(),
            'employee_weeks' => count($members), 'grand_total_seconds' => (int) $dayCollection->sum('billable_seconds'),
            'safe_to_send_client' => $blocking === [],
        ];
    }

    private function capWeeklyHours(array &$days, bool $fullShiftSandro): void
    {
        foreach (collect($days)->groupBy('employee_id') as $employeeDays) {
            $first = $employeeDays->first();
            $limit = $first['expected_weekly_regular_seconds'] + $first['expected_weekly_ot_seconds'];
            if ($fullShiftSandro && $first['employee'] === 'Sandro Kluivert Aplicano Flores'
                && $employeeDays->where('billable_seconds', '>', 0)->count() === 6) {
                $limit = max($limit, 48 * 3600);
                $saturday = collect($days)->search(fn (array $day): bool =>
                    $day['employee_id'] === $first['employee_id'] && $day['date'] === '2026-11-21');
                if ($saturday !== false) {
                    $days[$saturday]['comment'] = trim($days[$saturday]['comment']
                        .' | Excepción semanal autorizada: 48 h en seis jornadas', ' |');
                }
            }
            $excess = $limit > 0 ? max((int) $employeeDays->sum('billable_seconds') - $limit, 0) : 0;
            foreach ($employeeDays->sortByDesc('date') as $candidate) {
                if ($excess === 0) {
                    break;
                }
                $index = collect($days)->search(fn (array $day): bool =>
                    $day['employee_id'] === $candidate['employee_id'] && $day['date'] === $candidate['date']);
                $reduction = min($excess, $days[$index]['billable_seconds']);
                if ($reduction === 0) {
                    continue;
                }
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
