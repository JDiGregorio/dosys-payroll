<?php

namespace App\Filament\Resources\WorkScheduleTemplates\Pages;

use App\Filament\Resources\WorkScheduleTemplates\WorkScheduleTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkScheduleTemplate extends EditRecord
{
    protected static string $resource = WorkScheduleTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
