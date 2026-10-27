<?php

namespace App\Services\Reports;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollOvertimeAdjustment;
use App\Models\PayrollResult;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackabiProjectMapping;
use App\Services\ScheduleExpectationService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ClientWeeklyHoursReportService
{
    private function excludedFromClientReport(?Employee $employee): bool
    {
        return $employee !== null && (
            mb_strtolower(trim($employee->campaign?->name ?? '')) === 'dosys bpo'
            || mb_strtolower(trim($employee->name)) === 'jose di gregorio'
        );
    }

    public function __construct(private ScheduleExpectationService $schedule) {}

    public function build(string $from, string $to): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $to)->startOfDay();
        if ($start->toDateString() !== $from || $end->toDateString() !== $to || $start->gt($end)) {
            throw new InvalidArgumentException('Rango de fechas inválido.');
        }

        $reviews = DailyTimeReview::query()
            ->whereBetween('date', [$from, $to])
            ->with(['employee.campaign', 'employee.workScheduleTemplate.days', 'employee.scheduleAssignments.template.days',
                'employee.scheduleType', 'payrollPeriod'])
            ->orderBy('date')->orderBy('employee_id')->get()
            ->reject(fn (DailyTimeReview $review): bool => $this->excludedFromClientReport($review->employee));
        $resultKeys = PayrollResult::query()
            ->whereIn('payroll_period_id', $reviews->pluck('payroll_period_id')->unique())
            ->get(['payroll_period_id', 'employee_id'])
            ->mapWithKeys(fn (PayrollResult $result): array => [$result->payroll_period_id.'|'.$result->employee_id => true]);
        $reviewKeys = $reviews->keyBy(fn (DailyTimeReview $review): string => $review->payroll_period_id.'|'.$review->employee_id.'|'.$review->date->toDateString());
        $firstPeriodStarts = PayrollResult::query()->whereIn('employee_id', $reviews->pluck('employee_id')->unique())
            ->with('payrollPeriod')->get()->groupBy('employee_id')
            ->map(fn (Collection $results): ?string => $results->pluck('payrollPeriod.starts_at')
                ->filter()->map(fn ($date): string => $date->toDateString())->sort()->first());
        $firstNonOffDates = $reviews->where('paid_day_off', false)->groupBy('employee_id')
            ->map(fn (Collection $employeeReviews): ?string => $employeeReviews->min(fn (DailyTimeReview $review): string => $review->date->toDateString()));
        $entries = HubstaffTimeEntry::query()->whereBetween('date', [$from, $to])->where('active', true)
            ->whereNotNull('employee_id')->get(['employee_id', 'date', 'project', 'trackabi_project_id', 'total_seconds', 'source_provider']);
        $entryDays = $entries->groupBy(fn (HubstaffTimeEntry $entry): string => $entry->employee_id.'|'.$entry->date->toDateString());
        $activities = TrackabiDailyActivity::query()->whereBetween('activity_date', [$from, $to])
            ->where('source', 'trackabi_insights_csv')->orderByDesc('imported_at')->get()
            ->unique(fn (TrackabiDailyActivity $activity): string => $activity->employee_id.'|'.$activity->activity_date->toDateString())
            ->keyBy(fn (TrackabiDailyActivity $activity): string => $activity->employee_id.'|'.$activity->activity_date->toDateString());
        $mappings = TrackabiProjectMapping::query()->where('active', true)->pluck('campaign_id', 'trackabi_project_id');
        $mappingNames = TrackabiProjectMapping::query()->where('active', true)->whereNotNull('trackabi_project_name')
            ->get()->mapWithKeys(fn (TrackabiProjectMapping $mapping): array => [mb_strtolower(trim($mapping->trackabi_project_name)) => $mapping->campaign_id]);

        $warnings = [];
        $days = [];
        $excluded = [];
        $untrackedEmployeesWarned = [];
        foreach (PayrollResult::query()->whereIn('payroll_period_id', $reviews->pluck('payroll_period_id')->unique())
            ->with(['payrollPeriod', 'employee.campaign'])->get() as $result) {
            if ($this->excludedFromClientReport($result->employee)) {
                continue;
            }
            $periodStart = $result->payrollPeriod->starts_at->copy()->max($start);
            $periodEnd = $result->payrollPeriod->ends_at->copy()->min($end);
            foreach (CarbonPeriod::create($periodStart, $periodEnd) as $date) {
                if (! $reviewKeys->has($result->payroll_period_id.'|'.$result->employee_id.'|'.$date->toDateString())) {
                    $warnings[] = $this->warning('CLIENT_REPORT_DATA_MISSING', $result->employee, $date->toDateString(), 'Falta revisión diaria.');
                }
            }
        }
        foreach (PayrollOvertimeAdjustment::query()->whereIn('payroll_period_id', $reviews->pluck('payroll_period_id')->unique())
            ->where('active', true)->with('employee.campaign')->get() as $adjustment) {
            if ($this->excludedFromClientReport($adjustment->employee)) {
                continue;
            }
            $warnings[] = $this->warning('UNDATED_MANUAL_OT', $adjustment->employee, $from,
                'Ajuste manual de OT del período sin fecha diaria: '.$adjustment->hours.' horas. No se distribuyó automáticamente.');
        }
        foreach ($reviews as $review) {
            $employee = $review->employee;
            if (! $employee) {
                $warnings[] = $this->warning('CLIENT_REPORT_DATA_MISSING', null, $review->date->toDateString(), 'Revisión sin empleado.');

                continue;
            }
            $key = $review->payroll_period_id.'|'.$employee->id;
            if ($review->paid_day_off && $firstPeriodStarts->get($employee->id) !== null
                && $firstPeriodStarts->get($employee->id) >= $from
                && $firstNonOffDates->get($employee->id) !== null
                && $review->date->toDateString() < $firstNonOffDates->get($employee->id)) {
                $warnings[] = $this->warning('PRE_FIRST_WORKDAY_OFF', $employee, $review->date->toDateString(),
                    'OFF anterior a la primera revisión de trabajo de un empleado nuevo; excluido del reporte al cliente.');

                continue;
            }
            $hasEvidence = $resultKeys->has($key) || $entryDays->has($employee->id.'|'.$review->date->toDateString());
            if (! $hasEvidence) {
                $excluded[$employee->id] = ['employee' => $employee->name, 'reason' => 'Sin resultado de planilla ni tracking histórico en ese período'];

                continue;
            }
            unset($excluded[$employee->id]);
            if ($employee->paid_without_tracking && ! isset($untrackedEmployeesWarned[$employee->id])) {
                $warnings[] = $this->warning('CLIENT_REPORT_DATA_MISSING', $employee, $review->date->toDateString(),
                    'Pago sin tracking; no existe una regla aprobada de facturación al cliente.');
                $untrackedEmployeesWarned[$employee->id] = true;
            }
            $day = $this->day($review, $entryDays->get($employee->id.'|'.$review->date->toDateString(), collect()),
                $activities->get($employee->id.'|'.$review->date->toDateString()), $mappings, $mappingNames, $warnings);
            $days[] = $day;
        }

        $dayCollection = collect($days);
        foreach ($dayCollection->groupBy('employee_id') as $employeeDays) {
            $first = $employeeDays->first();
            if ($first['expected_weekly_regular_seconds'] === 0 && $employeeDays->contains(fn (array $day): bool => $day['expected_regular_seconds'] > 0)) {
                $warnings[] = ['code' => 'MISSING_WEEKLY_CONFIGURATION', 'employee' => $first['employee'],
                    'date' => $first['date'], 'message' => 'Hay jornada diaria, pero no expectativa semanal ordinaria configurada.'];
            }
        }
        $weeks = [];
        for ($week = $start->copy()->startOfWeek(Carbon::MONDAY); $week->lte($end); $week->addWeek()) {
            $weekStart = $week->toDateString();
            $weekEnd = $week->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
            $weekDays = $dayCollection->filter(fn (array $day): bool => $day['date'] >= $weekStart && $day['date'] <= $weekEnd);
            $members = $weekDays->groupBy('employee_id')->map(function (Collection $employeeDays) use ($weekStart, $weekEnd): array {
                $first = $employeeDays->first();
                $byDate = $employeeDays->keyBy('date');
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
            $weeks[] = ['start' => $weekStart, 'end' => $weekEnd, 'members' => $members,
                'total_seconds' => array_sum(array_column($members, 'total_seconds'))];
            if ($weekEnd <= $to && $weekStart >= $from) {
                foreach ($members as $member) {
                    $expected = $member['expected_weekly_regular_seconds'] + $member['expected_weekly_ot_seconds'];
                    if ($expected > 0 && $member['total_seconds'] > $expected + 60) {
                        $warnings[] = ['code' => 'WEEKLY_OVER_TARGET', 'employee' => $member['employee'],
                            'date' => $weekStart, 'message' => 'Las horas facturables superan la expectativa semanal configurada.'];
                    }
                }
            }
        }

        $campaigns = $dayCollection->groupBy('campaign')->map(fn (Collection $campaignDays, string $name): array => [
            'name' => $name,
            'employees' => $campaignDays->pluck('employee_id')->unique()->count(),
            'total_seconds' => (int) $campaignDays->sum('billable_seconds'),
        ])->sortKeys()->values()->all();

        return [
            'from' => $from, 'to' => $to, 'weeks' => $weeks, 'days' => $days,
            'campaigns' => $campaigns, 'warnings' => $warnings,
            'excluded' => array_values($excluded),
            'employee_count' => $dayCollection->pluck('employee_id')->unique()->count(),
            'employee_weeks' => array_sum(array_map(fn (array $week): int => count($week['members']), $weeks)),
            'grand_total_seconds' => (int) $dayCollection->sum('billable_seconds'),
            'safe_to_send_client' => $warnings === [],
        ];
    }

    public function calculateDay(array $facts): array
    {
        $ordinary = max((int) ($facts['ordinary_seconds'] ?? 0), 0);
        $overtime = max((int) ($facts['overtime_seconds'] ?? 0), 0);
        $base = $ordinary + $overtime;
        $trackable = max((int) ($facts['expected_trackable_seconds'] ?? $base), 0);
        $activity = max((int) ($facts['activity_seconds'] ?? 0), 0);
        $justified = max((int) ($facts['justified_seconds'] ?? 0), 0);
        $gap = array_key_exists('remaining_gap_seconds', $facts)
            ? max((int) $facts['remaining_gap_seconds'], 0)
            : max($trackable - $activity - $justified, 0);

        if ($facts['non_billable'] ?? false) {
            return ['base_seconds' => $base, 'remaining_gap_seconds' => $gap,
                'billable_seconds' => 0, 'rule' => $facts['non_billable_rule'] ?? 'JUSTIFIED_ABSENCE'];
        }
        if ($base === 0) {
            return ['base_seconds' => 0, 'remaining_gap_seconds' => 0,
                'billable_seconds' => 0, 'rule' => 'SCHEDULED_OFF'];
        }
        $billable = $gap >= 1800 ? max($base - $gap, 0) : $base;

        return ['base_seconds' => $base, 'remaining_gap_seconds' => $gap, 'billable_seconds' => $billable,
            'rule' => $gap === 0 ? 'FULL_SCHEDULE'
                : ($gap < 1800 ? 'LOSS_UNDER_30_MIN_TOLERATED' : 'LOSS_30_MIN_OR_MORE_DEDUCTED')];
    }

    private function day(DailyTimeReview $review, Collection $entries, ?TrackabiDailyActivity $activityRecord, Collection $mappings, Collection $mappingNames, array &$warnings): array
    {
        $employee = $review->employee;
        $date = $review->date->toDateString();
        $campaign = $employee->campaign?->name;
        if (! $campaign) {
            $warnings[] = $this->warning('CLIENT_REPORT_DATA_MISSING', $employee, $date, 'Campaña no configurada.');
        }
        foreach ($entries->pluck('trackabi_project_id')->filter()->unique() as $projectId) {
            $mappedCampaign = $mappings->get($projectId);
            if (! $mappedCampaign || (int) $mappedCampaign !== (int) $employee->campaign_id) {
                $warnings[] = $this->warning('AMBIGUOUS_HISTORICAL_CAMPAIGN', $employee, $date, "Proyecto Trackabi #{$projectId} no coincide con la campaña actual.");
            }
        }
        foreach ($entries->filter(fn (HubstaffTimeEntry $entry): bool => ! $entry->trackabi_project_id)
            ->pluck('project')->filter(fn (?string $name): bool => $name !== null && trim($name) !== '' && mb_strtolower(trim($name)) !== 'all')
            ->unique() as $projectName) {
            $mappedCampaign = $mappingNames->get(mb_strtolower(trim($projectName)));
            if ($mappedCampaign !== null && (int) $mappedCampaign !== (int) $employee->campaign_id) {
                $warnings[] = $this->warning('AMBIGUOUS_HISTORICAL_CAMPAIGN', $employee, $date,
                    "Proyecto Trackabi {$projectName} no coincide con la campaña actual.");
            }
        }

        $ordinary = max((int) $review->expected_ordinary_seconds, 0);
        $overtime = max((int) $review->preassigned_overtime_seconds, (int) $review->approved_overtime_seconds, 0)
            + max((int) $review->additional_overtime_seconds, 0);
        $trackable = max((int) $review->expected_hubstaff_seconds, 0);
        $directPeriod = $review->payrollPeriod?->starts_at->gte(Carbon::parse('2026-09-26'));
        $activity = $review->verified_tracked_seconds !== null
            ? (int) $review->verified_tracked_seconds
            : ($directPeriod ? $activityRecord?->measured_activity_seconds : null);
        $source = $review->verified_tracked_seconds !== null ? 'manual_verified'
            : ($activity !== null ? 'trackabi_insights_csv' : 'daily_review_consolidated');
        $justified = max((int) $review->justified_absence_seconds, 0);
        $noWork = $directPeriod
            ? ($activity === null || $activity === 0)
            : $entries->sum('total_seconds') === 0;
        $fullJustified = ! $review->paid_day_off && $noWork && $ordinary > 0 && $justified >= $ordinary;
        $fullUnjustified = ! $review->paid_day_off && $noWork && $ordinary > 0
            && (int) $review->unjustified_absence_seconds >= $ordinary;
        $offWorked = $review->paid_day_off && $review->off_worked
            && (int) $review->off_worked_verified_seconds > 0 && $activity !== null && $activity > 0;
        $nonBillable = ($review->paid_day_off && ! $offWorked) || $fullJustified || $fullUnjustified;
        $reason = $review->paid_day_off ? 'SCHEDULED_OFF'
            : ($fullJustified ? 'JUSTIFIED_ABSENCE' : 'UNJUSTIFIED_ABSENCE');
        if ($review->paid_day_off && $review->off_worked && ! $offWorked) {
            $warnings[] = $this->warning('CLIENT_REPORT_DATA_MISSING', $employee, $date, 'OFF trabajado sin actividad y horas verificadas suficientes.');
        }
        if ($review->paid_day_off && $activity !== null && $activity > 0 && ! $offWorked) {
            $warnings[] = $this->warning('OFF_WITH_ACTIVITY_UNVERIFIED', $employee, $date,
                'OFF con actividad medida; confirmar si fue trabajo facturable antes de enviar.');
        }
        if ($review->status === 'pendiente' && ($ordinary + $overtime > 0 || ($activity !== null && $activity > 0))) {
            $warnings[] = $this->warning('UNRESOLVED_REVIEW', $employee, $date, 'Revisión diaria pendiente.');
        }
        if ($directPeriod && ! $nonBillable && ! $employee->paid_without_tracking && $trackable > 0
            && ($activity === null || $activity === 0) && ! $fullJustified && ! $fullUnjustified) {
            $warnings[] = $this->warning('CLIENT_REPORT_DATA_MISSING', $employee, $date, 'No hay actividad medida ni verificación manual para una jornada esperada.');
        }
        if ($employee->paid_without_tracking && ! $nonBillable) {
            $nonBillable = true;
            $reason = 'MISSING_DATA';
        }
        if ($ordinary > 0 && $trackable === 0 && ! $nonBillable) {
            $warnings[] = $this->warning('CLIENT_REPORT_DATA_MISSING', $employee, $date, 'Falta expectativa trackeable.');
        }
        $comments = trim(implode(' | ', array_filter([$review->supervisor_comment, $review->rrhh_comment])));
        if ($fullJustified && preg_match('/trabaj|work|training|entren|track|timer/i', $comments)) {
            $warnings[] = $this->warning('AMBIGUOUS_FULL_DAY_JUSTIFICATION', $employee, $date,
                'Se pagó el día completo sin actividad medida, pero el comentario indica posible trabajo; validar facturación.');
        }
        if (! $review->paid_day_off && preg_match('/vacacion|vacation|feriado|holiday|permiso|medical leave|licencia/i', $comments)) {
            $warnings[] = $this->warning('AMBIGUOUS_LEAVE_OR_HOLIDAY', $employee, $date,
                'El comentario sugiere ausencia o feriado, pero no existe una categoría diaria facturable inequívoca.');
        }
        $knownTimeLossSource = in_array($review->lost_time_source, [
            'trackabi_insights_activity', 'historical_estimate', 'manual_trackabi_verification', 'supervisor_adjustment',
        ], true);
        if (! $fullJustified && ! $review->paid_day_off && $justified > 0 && ! $knownTimeLossSource) {
            $warnings[] = $this->warning('UNCLASSIFIED_PARTIAL_JUSTIFICATION', $employee, $date,
                'La justificación parcial no distingue permiso no facturable de ajuste de tracking.');
        }

        $base = $ordinary + $overtime;
        $finalGap = max($base - max((int) $review->payable_seconds, 0), 0);
        if ($review->lost_time_source !== null) {
            $finalGap = max($finalGap, $review->finalEstimatedLostSeconds());
        }
        $calculated = $this->calculateDay([
            'ordinary_seconds' => $ordinary, 'overtime_seconds' => $overtime,
            'expected_trackable_seconds' => $trackable, 'activity_seconds' => $activity ?? 0,
            'justified_seconds' => $justified, 'remaining_gap_seconds' => $finalGap,
            'non_billable' => $nonBillable,
            'non_billable_rule' => $reason,
        ]);
        if ($offWorked && $calculated['billable_seconds'] > 0) {
            $calculated['rule'] = 'WEEKEND_OVERTIME';
        } elseif ($ordinary === 0 && $overtime > 0 && $calculated['billable_seconds'] > 0) {
            $calculated['rule'] = 'WEEKEND_OVERTIME';
        }
        $schedule = $this->schedule->forDate($employee, $review->date);
        if ($ordinary > 0 && $schedule['expected_ordinary_seconds'] > 0
            && abs($ordinary - $schedule['expected_ordinary_seconds']) > 60) {
            $warnings[] = $this->warning('HISTORICAL_SCHEDULE_DIFFERS', $employee, $date, 'La plantilla actual difiere de la expectativa guardada en la revisión.');
        }

        return [
            'week_start' => $review->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'week_end' => $review->date->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'campaign' => $campaign ?: 'SIN CAMPAÑA', 'employee_id' => $employee->id,
            'employee' => $employee->name, 'date' => $date,
            'expected_regular_seconds' => $ordinary, 'approved_ot_seconds' => $overtime,
            'base_seconds' => $calculated['base_seconds'], 'expected_trackable_seconds' => $trackable,
            'activity_source' => $source, 'desktop_activity_seconds' => $activity,
            'justified_seconds' => $justified, 'remaining_gap_seconds' => $calculated['remaining_gap_seconds'],
            'rule' => $calculated['rule'], 'billable_seconds' => $calculated['billable_seconds'],
            'comment' => $comments,
            'expected_weekly_regular_seconds' => (int) round((float) ($employee->ordinary_weekly_hours ?: $employee->weekly_hours) * 3600),
            'expected_weekly_ot_seconds' => (int) round((float) $employee->preassigned_overtime_weekly_hours * 3600),
            'review_status' => $review->status, 'schedule_name' => $employee->workScheduleTemplate?->name,
            'active_now' => (bool) $employee->active,
        ];
    }

    private function warning(string $code, ?Employee $employee, string $date, string $message): array
    {
        return ['code' => $code, 'employee' => $employee?->name ?? 'N/D', 'date' => $date, 'message' => $message];
    }
}
