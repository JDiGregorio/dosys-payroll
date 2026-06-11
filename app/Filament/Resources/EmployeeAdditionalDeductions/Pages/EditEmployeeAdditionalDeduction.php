<?php

namespace App\Filament\Resources\EmployeeAdditionalDeductions\Pages;

use App\Filament\Resources\EmployeeAdditionalDeductions\EmployeeAdditionalDeductionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeAdditionalDeduction extends EditRecord
{
    protected static string $resource = EmployeeAdditionalDeductionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
