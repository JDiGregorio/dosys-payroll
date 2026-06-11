<?php

namespace App\Filament\Resources\PayrollBonuses\Pages;

use App\Filament\Resources\PayrollBonuses\PayrollBonusResource;
use App\Services\PayrollCalculationService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayrollBonus extends EditRecord
{
    protected static string $resource = PayrollBonusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PayrollBonusResource::normalizeScopeData($data);
    }

    protected function afterSave(): void
    {
        app(PayrollCalculationService::class)->recalculatePayrollResults($this->record->payrollPeriod);
    }
}
