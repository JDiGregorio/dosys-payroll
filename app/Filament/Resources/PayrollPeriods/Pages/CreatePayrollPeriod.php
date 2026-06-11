<?php

namespace App\Filament\Resources\PayrollPeriods\Pages;

use App\Filament\Resources\PayrollPeriods\PayrollPeriodResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayrollPeriod extends CreateRecord
{
    protected static string $resource = PayrollPeriodResource::class;

    private array $deductionTypeIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->deductionTypeIds = $data['deduction_type_ids'] ?? [];
        unset($data['deduction_type_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->deductionTypes()->sync($this->deductionTypeIds);
    }
}
