<?php

namespace App\Filament\Resources\WorkScheduleTemplates\Pages;

use App\Filament\Resources\WorkScheduleTemplates\WorkScheduleTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkScheduleTemplates extends ListRecords
{
    protected static string $resource = WorkScheduleTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear plantilla'),
        ];
    }
}
