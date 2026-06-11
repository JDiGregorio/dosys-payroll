<?php

namespace App\Filament\Resources\HourlyRateTypes\Pages;

use App\Filament\Resources\HourlyRateTypes\HourlyRateTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHourlyRateType extends EditRecord
{
    protected static string $resource = HourlyRateTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
