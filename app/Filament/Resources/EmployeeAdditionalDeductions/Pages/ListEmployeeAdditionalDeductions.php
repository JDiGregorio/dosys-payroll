<?php

namespace App\Filament\Resources\EmployeeAdditionalDeductions\Pages;

use App\Filament\Resources\EmployeeAdditionalDeductions\EmployeeAdditionalDeductionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeAdditionalDeductions extends ListRecords
{
    protected static string $resource = EmployeeAdditionalDeductionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear deducción adicional'),
        ];
    }
}
