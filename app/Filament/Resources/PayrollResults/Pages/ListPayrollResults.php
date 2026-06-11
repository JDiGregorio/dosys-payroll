<?php

namespace App\Filament\Resources\PayrollResults\Pages;

use App\Filament\Resources\PayrollResults\PayrollResultResource;
use Filament\Resources\Pages\ListRecords;

class ListPayrollResults extends ListRecords
{
    protected static string $resource = PayrollResultResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
