<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\ScheduleType;
use App\Models\WorkScheduleTemplate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PalmettoDebtCollectionsScheduleCorrectionService
{
    private const WEEKDAY_NAMES = [
        1 => 'lunes',
        2 => 'martes',
        3 => 'miércoles',
        4 => 'jueves',
        5 => 'viernes',
    ];

    public function __construct(
        private readonly PayrollCalculationService $payrollCalculationService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function preview(PayrollPeriod $period): array
    {
        $this->ensureTemplates();

        return $this->targetEmployees()
            ->map(function (Employee $employee) use ($period): array {
                $weekday = $this->inferEightHourWeekday($employee, $period);
                $reviews = DailyTimeReview::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id);

                return [
                    'employee_id' => $employee->id,
                    'name' => $employee->name,
                    'current_template' => $employee->workScheduleTemplate?->name ?? 'Sin plantilla',
                    'eight_hour_weekday' => $weekday ? self::WEEKDAY_NAMES[$weekday] : 'No inferido',
                    'template' => $weekday ? $this->templateNameForWeekday($weekday) : 'Requiere revisión',
                    'reviews' => (clone $reviews)->count(),
                    'reviewed' => (clone $reviews)
                        ->where(fn ($query) => $query
                            ->where('status', '!=', 'pendiente')
                            ->orWhere('justified_absence_seconds', '>', 0)
                            ->orWhere('paid_day_off', true)
                            ->orWhereNotNull('supervisor_comment')
                            ->orWhereNotNull('rrhh_comment'))
                        ->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function apply(PayrollPeriod $period, bool $skipUninferred = false): array
    {
        return DB::transaction(function () use ($period, $skipUninferred): array {
            $rows = collect($this->preview($period));
            $notInferred = $rows->where('eight_hour_weekday', 'No inferido');

            if ($notInferred->isNotEmpty() && ! $skipUninferred) {
                throw new RuntimeException(
                    'No se pudo inferir el día de 8 horas para: '.$notInferred->pluck('name')->implode(', '),
                );
            }

            $rowsToApply = $skipUninferred
                ? $rows->where('eight_hour_weekday', '!=', 'No inferido')->values()
                : $rows;

            if ($rowsToApply->isEmpty()) {
                throw new RuntimeException('No hay empleados con día de 8 horas inferido para aplicar.');
            }

            $diurnaScheduleId = ScheduleType::query()->where('code', 'diurna')->value('id');

            if (! $diurnaScheduleId) {
                throw new RuntimeException('No existe una jornada Diurna configurada.');
            }

            $employeesById = $this->targetEmployees()->keyBy('id');
            $protectedReviewState = $this->manualReviewState($period);

            foreach ($rowsToApply as $row) {
                $employee = $employeesById->get($row['employee_id']);
                $weekday = array_search($row['eight_hour_weekday'], self::WEEKDAY_NAMES, true);
                $template = WorkScheduleTemplate::query()
                    ->where('name', $this->templateNameForWeekday((int) $weekday))
                    ->firstOrFail();

                $employee->update([
                    'schedule_type_id' => $diurnaScheduleId,
                    'work_schedule_template_id' => $template->id,
                    'weekly_hours' => 36,
                    'ordinary_weekly_hours' => 36,
                    'daily_hours' => 7,
                ]);
            }

            $this->payrollCalculationService->recalculatePeriodPreservingManual($period);

            if ($protectedReviewState !== $this->manualReviewState($period)) {
                throw new RuntimeException('La corrección intentó modificar información manual de revisiones.');
            }

            return $rowsToApply->all();
        });
    }

    private function targetEmployees(): Collection
    {
        return Employee::query()
            ->with('workScheduleTemplate')
            ->where('active', true)
            ->whereHas('campaign', fn ($query) => $query->where('name', 'PALMETTO'))
            ->whereHas('team', fn ($query) => $query->where('name', 'DEBT COLLECTIONS'))
            ->where(function ($query): void {
                $query->whereBetween('ordinary_weekly_hours', [35.5, 36.5])
                    ->orWhereBetween('weekly_hours', [35.5, 36.5])
                    ->orWhereBetween('daily_hours', [7.15, 7.25]);
            })
            ->where(function ($query): void {
                $query->whereNull('ordinary_weekly_hours')
                    ->orWhere('ordinary_weekly_hours', '<', 39);
            })
            ->orderBy('name')
            ->get();
    }

    private function inferEightHourWeekday(Employee $employee, PayrollPeriod $period): ?int
    {
        $totalsByDate = HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->where('active', true)
            ->whereBetween('date', [$period->starts_at, $period->ends_at])
            ->selectRaw('date, SUM(total_seconds) as total_seconds')
            ->groupBy('date')
            ->get()
            ->filter(fn ($row): bool => (int) $row->total_seconds > 0);

        if ($totalsByDate->isEmpty()) {
            return null;
        }

        return $totalsByDate
            ->groupBy(fn ($row): int => Carbon::parse($row->date)->dayOfWeekIso)
            ->filter(fn (Collection $rows, int $weekday): bool => isset(self::WEEKDAY_NAMES[$weekday]))
            ->map(fn (Collection $rows): float => (float) $rows->avg('total_seconds'))
            ->sortDesc()
            ->keys()
            ->first();
    }

    private function ensureTemplates(): void
    {
        foreach (array_keys(self::WEEKDAY_NAMES) as $weekday) {
            $template = WorkScheduleTemplate::query()->firstOrCreate(
                ['name' => $this->templateNameForWeekday($weekday)],
                [
                    'schedule_type' => 'diurna',
                    'description' => 'Patrón 36h con 8 horas el '.$this->weekdayName($weekday).' y 7 horas los demás días laborables.',
                    'active' => true,
                ],
            );

            $template->update([
                'schedule_type' => 'diurna',
                'description' => 'Patrón 36h con 8 horas el '.$this->weekdayName($weekday).' y 7 horas los demás días laborables.',
                'active' => true,
            ]);

            foreach (array_keys(self::WEEKDAY_NAMES) as $dayNumber) {
                $template->days()->updateOrCreate(
                    ['day_number' => $dayNumber],
                    [
                        'expected_seconds' => ($dayNumber === $weekday ? 8 : 7) * 3600,
                        'is_working_day' => true,
                    ],
                );
            }
        }
    }

    private function templateNameForWeekday(int $weekday): string
    {
        return 'Diurna 36h - '.$this->weekdayName($weekday).' 8h';
    }

    private function weekdayName(int $weekday): string
    {
        return self::WEEKDAY_NAMES[$weekday] ?? 'día no configurado';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manualReviewState(PayrollPeriod $period): array
    {
        $fields = [
            'justified_idle_seconds',
            'unjustified_idle_seconds',
            'justified_absence_seconds',
            'unjustified_absence_seconds',
            'approved_overtime_seconds',
            'assigned_overtime_fulfilled',
            'paid_day_off',
            'overtime_rate_type_id',
            'overtime_comment',
            'supervisor_comment',
            'rrhh_comment',
            'status',
            'reviewed_by',
            'approved_by',
        ];

        return DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (DailyTimeReview $review): array => [
                $review->id => $this->normalizedManualReviewState($review, $fields),
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function normalizedManualReviewState(DailyTimeReview $review, array $fields): array
    {
        $state = $review->only($fields);

        if ($review->paid_day_off) {
            $state['justified_absence_seconds'] = 0;
            $state['unjustified_absence_seconds'] = 0;
        }

        return $state;
    }
}
