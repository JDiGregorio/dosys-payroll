<?php

namespace App\Filament\Resources\PayrollBonuses\Pages;

use App\Filament\Resources\PayrollBonuses\PayrollBonusResource;
use App\Services\PayrollCalculationService;
use Filament\Resources\Pages\CreateRecord;

class CreatePayrollBonus extends CreateRecord
{
    protected static string $resource = PayrollBonusResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['proposed_by'] = auth()->id();
        $data['status'] = 'aprobado';

        return PayrollBonusResource::normalizeScopeData($data);
    }

    protected function afterCreate(): void
    {
        app(PayrollCalculationService::class)->recalculatePayrollResults($this->record->payrollPeriod);
    }
}
