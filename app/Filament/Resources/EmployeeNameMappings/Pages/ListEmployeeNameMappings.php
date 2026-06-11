<?php

namespace App\Filament\Resources\EmployeeNameMappings\Pages;

use App\Filament\Resources\EmployeeNameMappings\EmployeeNameMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeNameMappings extends ListRecords
{
    protected static string $resource = EmployeeNameMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
