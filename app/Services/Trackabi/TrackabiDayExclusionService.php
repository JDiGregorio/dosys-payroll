<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackabiDayExclusion;
use App\Services\PayrollCalculationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TrackabiDayExclusionService
{
    public function __construct(private readonly PayrollCalculationService $payroll) {}

    public function apply(PayrollPeriod $period, Employee $employee, Carbon $date, string $reason): TrackabiDayExclusion
    {
        $dateString = $date->toDateString();
        if (! in_array($period->status, ['borrador', 'importado', 'en_revision'], true)
            || $dateString < $period->starts_at->toDateString()
            || $dateString > $period->ends_at->toDateString()) {
            throw new RuntimeException('La exclusión requiere una fecha dentro de un período abierto.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Indica el motivo de la exclusión.');
        }

        return DB::transaction(function () use ($period, $employee, $dateString, $reason): TrackabiDayExclusion {
            $locked = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (! in_array($locked->status, ['borrador', 'importado', 'en_revision'], true)) {
                throw new RuntimeException('No se modifica un período cerrado.');
            }
            $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('date', $dateString)
                ->lockForUpdate()->firstOrFail();
            if ($review->status !== 'pendiente' || $review->paid_day_off
                || (int) $review->justified_absence_seconds > 0
                || (int) $review->supervisor_adjustment_seconds !== 0) {
                throw new RuntimeException('La fecha ya tiene una decisión de revisión; no se excluye automáticamente.');
            }

            $key = ['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'date' => $dateString];
            $existing = TrackabiDayExclusion::query()->where($key)->first();
            $activity = TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('activity_date', $dateString)->get();
            $archived = $existing?->archived_insights ?? $activity->map(fn (TrackabiDailyActivity $row): array => $row->getAttributes())->all();
            $exclusion = TrackabiDayExclusion::query()->updateOrCreate($key, [
                'reason' => trim($reason),
                'archived_insights' => $archived,
                'excluded_by' => auth()->id(),
            ]);
            HubstaffTimeEntry::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('date', $dateString)
                ->where('source_provider', 'trackabi_api')->update(['active' => false]);
            TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('activity_date', $dateString)->delete();
            $this->payroll->recalculateEmployeePreservingManual($locked, $employee);

            return $exclusion;
        });
    }
}
