<?php

namespace App\Filament\Resources\PayrollOvertimeAdjustments\Pages;

use App\Filament\Resources\PayrollOvertimeAdjustments\PayrollOvertimeAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayrollOvertimeAdjustments extends ListRecords
{
    protected static string $resource = PayrollOvertimeAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Agregar horas extras'),
        ];
    }
}
