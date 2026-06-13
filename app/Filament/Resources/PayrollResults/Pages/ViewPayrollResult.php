<?php

namespace App\Filament\Resources\PayrollResults\Pages;

use App\Filament\Resources\PayrollResults\PayrollResultResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPayrollResult extends ViewRecord
{
    protected static string $resource = PayrollResultResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
