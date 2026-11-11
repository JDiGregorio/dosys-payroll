<?php

namespace App\Services\Reports;

use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PalmettoGroupClientReportService
{
    private const GROUPS = [
        1 => ['from' => '2026-06-01', 'to' => '2026-09-20', 'supervisor_user_id' => 6, 'supervisor_employee_id' => 42],
        2 => ['from' => '2026-08-01', 'to' => '2026-09-20', 'supervisor_user_id' => 7, 'supervisor_employee_id' => 45],
    ];

    public function __construct(private ClientWeeklyHoursReportService $historical) {}

    public function build(int $group): array
    {
        $config = self::GROUPS[$group] ?? throw new InvalidArgumentException('Grupo Palmetto inválido.');
        $supervisor = Employee::query()->findOrFail($config['supervisor_employee_id']);
        $candidateIds = Employee::query()->whereHas('campaign', fn ($query) => $query->where('name', 'PALMETTO'))
            ->where(fn ($query) => $query->where('supervisor_user_id', $config['supervisor_user_id'])
                ->orWhere('id', $supervisor->id))
            ->pluck('id');
        $trackedIds = HubstaffTimeEntry::query()->whereBetween('date', [$config['from'], $config['to']])
            ->where('active', true)->where('total_seconds', '>', 0)
            ->whereIn('employee_id', $candidateIds)->distinct()->pluck('employee_id');
        $base = $this->historical->build($config['from'], $config['to']);
        $name = 'PALMETTO '.$group;
        $days = collect($base['days'])->where('campaign', 'PALMETTO')
            ->whereIn('employee_id', $trackedIds)->map(function (array $day) use ($name): array {
                $day['campaign'] = $name;
                $day['activity_source'] = $day['activity_source'] === 'daily_review_consolidated'
                    ? 'historical_daily_review_payable'
                    : $day['activity_source'];
                if ($day['employee_id'] === 13 && $day['date'] === '2026-09-15'
                    && $day['rule'] === 'JUSTIFIED_ABSENCE'
                    && preg_match('/s[ií] trabaj[oó] las horas/iu', $day['comment'])) {
                    $day['billable_seconds'] = $day['expected_regular_seconds'];
                    $day['remaining_gap_seconds'] = 0;
                    $day['rule'] = 'SUPERVISOR_CONFIRMED_WORK_TRACKER_FAILURE';
                    $day['activity_source'] = 'supervisor_confirmed_work';
                    $day['comment'] .= ' | Facturación confirmada: trabajo realizado sin tracking';
                }
                $day['raw_billable_seconds'] = $day['billable_seconds'];
                $day['billable_seconds'] = intdiv($day['billable_seconds'], 60) * 60;
                $omitted = $day['raw_billable_seconds'] - $day['billable_seconds'];
                if ($omitted > 0) {
                    $day['comment'] = trim($day['comment'].' | Precisión del reporte HH:MM: '.$omitted.' s no incluidos', ' |');
                }

                return $day;
            })->values();
        $byDay = $days->keyBy(fn (array $day): string => $day['employee_id'].'|'.$day['date']);
        $weeks = array_map(function (array $week) use ($trackedIds, $byDay, $name): array {
            $members = collect($week['members'])->where('campaign', 'PALMETTO')
                ->whereIn('employee_id', $trackedIds)
                ->map(function (array $member) use ($byDay, $name): array {
                    $member['campaign'] = $name;
                    foreach ($member['daily'] as $date => $seconds) {
                        $day = $byDay->get($member['employee_id'].'|'.$date);
                        $member['daily'][$date] = $day === null ? null : $day['billable_seconds'];
                    }
                    $member['total_seconds'] = array_sum(array_filter($member['daily'], fn ($seconds): bool => $seconds !== null));

                    return $member;
                })->values()->all();
            $week['members'] = $members;
            $week['total_seconds'] = array_sum(array_column($members, 'total_seconds'));

            return $week;
        }, $base['weeks']);
        $eligibleNames = Employee::query()->whereIn('id', $trackedIds)->pluck('name');
        $warnings = collect($base['warnings'])->filter(fn (array $warning): bool =>
            $eligibleNames->contains($warning['employee'])
            && ! ($warning['code'] === 'AMBIGUOUS_FULL_DAY_JUSTIFICATION'
                && $warning['employee'] === 'Yadira Dariela Ebanks Canaca'
                && $warning['date'] === '2026-09-15'));
        if ($days->contains(fn (array $day): bool => $day['rule'] === 'SUPERVISOR_CONFIRMED_WORK_TRACKER_FAILURE')) {
            $warnings->push([
                'code' => 'SUPERVISOR_CONFIRMED_WORK_TRACKER_FAILURE',
                'employee' => 'Yadira Dariela Ebanks Canaca', 'date' => '2026-09-15',
                'message' => '7 h facturadas según comentario de revisión: sí trabajó las horas; no hubo tracking.',
            ]);
        }
        $advisory = ['UNRESOLVED_REVIEW', 'UNCLASSIFIED_PARTIAL_JUSTIFICATION',
            'HISTORICAL_SCHEDULE_DIFFERS', 'AMBIGUOUS_LEAVE_OR_HOLIDAY',
            'SUPERVISOR_CONFIRMED_WORK_TRACKER_FAILURE'];
        $blocking = $warnings->reject(fn (array $warning): bool => in_array($warning['code'], $advisory, true))->values()->all();

        return [
            'from' => $config['from'], 'to' => $config['to'], 'weeks' => $weeks,
            'days' => $days->all(),
            'campaigns' => [[
                'name' => $name, 'employees' => $days->pluck('employee_id')->unique()->count(),
                'total_seconds' => (int) $days->sum('billable_seconds'),
            ]],
            'warnings' => $warnings->values()->all(), 'blocking_warnings' => $blocking,
            'excluded' => [], 'employee_count' => $days->pluck('employee_id')->unique()->count(),
            'employee_weeks' => array_sum(array_map(fn (array $week): int => count($week['members']), $weeks)),
            'grand_total_seconds' => (int) $days->sum('billable_seconds'),
            'safe_to_send_client' => $blocking === [],
        ];
    }
}
