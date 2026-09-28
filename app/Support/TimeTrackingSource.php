<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\HubstaffTimeEntry;

class TimeTrackingSource
{
    public static function labelForEmployee(?Employee $employee, ?int $periodId = null, ?string $date = null): string
    {
        if (! $employee) {
            return 'Hubstaff';
        }

        if ($periodId) {
            $providers = HubstaffTimeEntry::query()
                ->where('employee_id', $employee->id)
                ->where('payroll_period_id', $periodId)
                ->where('active', true)
                ->when($date, fn ($query) => $query->whereDate('date', $date))
                ->distinct()->pluck('source_provider');
            if ($providers->isNotEmpty()) {
                $trackabi = $providers->contains(fn ($provider) => in_array($provider, ['trackabi', 'trackabi_api'], true));
                $hubstaff = $providers->contains(fn ($provider) => ! in_array($provider, ['trackabi', 'trackabi_api'], true));

                return $trackabi ? ($hubstaff ? 'Trackabi / Hubstaff' : 'Trackabi') : 'Hubstaff';
            }
        }

        $campaign = $employee->relationLoaded('campaign')
            ? $employee->campaign
            : $employee->campaign()->first();

        return strcasecmp((string) $campaign?->name, 'Palmetto') === 0
            ? 'Trackabi'
            : 'Hubstaff';
    }
}
