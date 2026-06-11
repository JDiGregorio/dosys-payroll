<?php

namespace App\Filament\Resources\PayrollDeductions\Pages;

use App\Filament\Resources\PayrollDeductions\PayrollDeductionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayrollDeductions extends ListRecords
{
    protected static string $resource = PayrollDeductionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
