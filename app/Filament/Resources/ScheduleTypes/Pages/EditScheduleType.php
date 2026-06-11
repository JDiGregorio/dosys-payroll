<?php

namespace App\Filament\Resources\ScheduleTypes\Pages;

use App\Filament\Resources\ScheduleTypes\ScheduleTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScheduleType extends EditRecord
{
    protected static string $resource = ScheduleTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
