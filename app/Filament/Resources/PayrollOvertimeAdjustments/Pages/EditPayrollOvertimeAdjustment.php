<?php

namespace App\Filament\Resources\PayrollOvertimeAdjustments\Pages;

use App\Filament\Resources\PayrollOvertimeAdjustments\PayrollOvertimeAdjustmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayrollOvertimeAdjustment extends EditRecord
{
    protected static string $resource = PayrollOvertimeAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PayrollOvertimeAdjustmentResource::normalizeAmount($data);
    }
}
