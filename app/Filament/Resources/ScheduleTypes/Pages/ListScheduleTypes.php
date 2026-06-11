<?php

namespace App\Filament\Resources\ScheduleTypes\Pages;

use App\Filament\Resources\ScheduleTypes\ScheduleTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScheduleTypes extends ListRecords
{
    protected static string $resource = ScheduleTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
