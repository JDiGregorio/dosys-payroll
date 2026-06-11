<?php

namespace App\Filament\Resources\HourlyRateTypes\Pages;

use App\Filament\Resources\HourlyRateTypes\HourlyRateTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHourlyRateTypes extends ListRecords
{
    protected static string $resource = HourlyRateTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
