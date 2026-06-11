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
        $user = auth()->user();

        if (! $period) {
            return [
                Stat::make('Período activo', 'Sin período'),
            ];
        }

        $reviewQuery = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->whereHas('employee', fn ($query) => $query->visibleTo($user));

        $resultQuery = $period->payrollResults()
            ->whereHas('employee', fn ($query) => $query->visibleTo($user));

        return array_values(array_filter([
            $user?->isRrhh()
                ? Stat::make('Empleados sin mapeo', HubstaffTimeEntry::query()->where('payroll_period_id', $period->id)->whereNull('employee_id')->distinct('hubstaff_member')->count('hubstaff_member'))
                : null,
            Stat::make('Revisiones pendientes', (clone $reviewQuery)->where('status', 'pendiente')->count()),
            Stat::make('Revisiones aplicadas', (clone $reviewQuery)->whereIn('status', ['revisado_supervisor', 'aprobado_rrhh'])->count()),
            Stat::make('Planillas calculadas', $resultQuery->count()),
        ]));
    }
}
