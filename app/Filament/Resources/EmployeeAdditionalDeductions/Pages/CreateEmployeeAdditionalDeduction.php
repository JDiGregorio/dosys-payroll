<?php

namespace App\Filament\Resources\EmployeeAdditionalDeductions\Pages;

use App\Filament\Resources\EmployeeAdditionalDeductions\EmployeeAdditionalDeductionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeAdditionalDeduction extends CreateRecord
{
    protected static string $resource = EmployeeAdditionalDeductionResource::class;

    protected function afterCreate(): void
    {
        EmployeeAdditionalDeductionResource::recalculatePeriod($this->record);
    }
}
