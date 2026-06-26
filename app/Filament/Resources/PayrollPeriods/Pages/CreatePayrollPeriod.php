<?php

namespace App\Filament\Resources\PayrollPeriods\Pages;

use App\Filament\Resources\PayrollPeriods\PayrollPeriodResource;
use App\Models\PayrollPeriod;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreatePayrollPeriod extends CreateRecord
{
    protected static string $resource = PayrollPeriodResource::class;

    private array $deductionTypeIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (PayrollPeriod::hasOpenPeriod()) {
            throw ValidationException::withMessages([
                'name' => 'No puedes crear un período nuevo mientras exista un período abierto.',
            ]);
        }

        $this->deductionTypeIds = $data['deduction_type_ids'] ?? [];
        unset($data['deduction_type_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->deductionTypes()->sync($this->deductionTypeIds);
    }
}
