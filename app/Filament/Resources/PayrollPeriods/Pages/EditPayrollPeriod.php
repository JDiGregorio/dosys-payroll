<?php

namespace App\Filament\Resources\PayrollPeriods\Pages;

use App\Filament\Resources\PayrollPeriods\PayrollPeriodResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayrollPeriod extends EditRecord
{
    protected static string $resource = PayrollPeriodResource::class;

    private array $deductionTypeIds = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->deductionTypeIds = $data['deduction_type_ids'] ?? [];
        unset($data['deduction_type_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->deductionTypes()->sync($this->deductionTypeIds);
    }
}
