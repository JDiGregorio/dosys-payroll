<?php

namespace App\Support;

use App\Models\Employee;

class TimeTrackingSource
{
    public static function labelForEmployee(?Employee $employee): string
    {
        if (! $employee) {
            return 'Hubstaff';
        }

        $campaign = $employee->relationLoaded('campaign')
            ? $employee->campaign
            : $employee->campaign()->first();

        return strcasecmp((string) $campaign?->name, 'Palmetto') === 0
            ? 'Trackabi'
            : 'Hubstaff';
    }
}
