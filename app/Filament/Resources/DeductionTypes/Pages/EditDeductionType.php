<?php

namespace App\Filament\Resources\DeductionTypes\Pages;

use App\Filament\Resources\DeductionTypes\DeductionTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeductionType extends EditRecord
{
    protected static string $resource = DeductionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
