<?php

namespace App\Filament\Resources\EmployeeDeductions\Pages;

use App\Filament\Resources\EmployeeDeductions\EmployeeDeductionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeDeduction extends EditRecord
{
    protected static string $resource = EmployeeDeductionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
