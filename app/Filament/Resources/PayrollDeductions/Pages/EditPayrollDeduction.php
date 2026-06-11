<?php

namespace App\Filament\Resources\PayrollDeductions\Pages;

use App\Filament\Resources\PayrollDeductions\PayrollDeductionResource;
use App\Services\PayrollCalculationService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayrollDeduction extends EditRecord
{
    protected static string $resource = PayrollDeductionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    protected function afterSave(): void
    {
        app(PayrollCalculationService::class)->recalculateEmployeePayrollResult($this->record->payrollPeriod, $this->record->employee);
    }
}
