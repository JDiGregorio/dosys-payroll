<?php

namespace App\Filament\Resources\HubstaffProjectMappings\Pages;

use App\Filament\Resources\HubstaffProjectMappings\HubstaffProjectMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHubstaffProjectMappings extends ListRecords
{
    protected static string $resource = HubstaffProjectMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
