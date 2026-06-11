<?php

namespace App\Filament\Resources\PayrollResults\Pages;

use App\Filament\Resources\PayrollResults\PayrollResultResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayrollResult extends EditRecord
{
    protected static string $resource = PayrollResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
