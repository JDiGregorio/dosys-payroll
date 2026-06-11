<?php

namespace App\Filament\Widgets;

use App\Models\DailyTimeReview;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PayrollStatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $period = PayrollPeriod::query()->where('status', '!=', 'cerrado')->orderByDesc('starts_at')->first();

        if (! $period) {
            return [
                Stat::make('Período activo', 'Sin período'),
            ];
        }

        return [
            Stat::make('Empleados sin mapeo', HubstaffTimeEntry::query()->where('payroll_period_id', $period->id)->whereNull('employee_id')->distinct('hubstaff_member')->count('hubstaff_member')),
            Stat::make('Revisiones pendientes', DailyTimeReview::query()->where('payroll_period_id', $period->id)->where('status', 'pendiente')->count()),
            Stat::make('Revisiones aplicadas', DailyTimeReview::query()->where('payroll_period_id', $period->id)->whereIn('status', ['revisado_supervisor', 'aprobado_rrhh'])->count()),
            Stat::make('Planillas calculadas', $period->payrollResults()->count()),
        ];
    }
}
