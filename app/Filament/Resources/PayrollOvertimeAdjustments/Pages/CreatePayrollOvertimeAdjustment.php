<?php

namespace App\Filament\Resources\PayrollOvertimeAdjustments\Pages;

use App\Filament\Resources\PayrollOvertimeAdjustments\PayrollOvertimeAdjustmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayrollOvertimeAdjustment extends CreateRecord
{
    protected static string $resource = PayrollOvertimeAdjustmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PayrollOvertimeAdjustmentResource::normalizeAmount($data);
    }
}
