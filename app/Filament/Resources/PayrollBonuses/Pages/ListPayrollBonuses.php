<?php

namespace App\Filament\Resources\PayrollBonuses\Pages;

use App\Filament\Resources\PayrollBonuses\PayrollBonusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayrollBonuses extends ListRecords
{
    protected static string $resource = PayrollBonusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
