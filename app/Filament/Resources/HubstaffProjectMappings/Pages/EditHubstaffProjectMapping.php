<?php

namespace App\Filament\Resources\HubstaffProjectMappings\Pages;

use App\Filament\Resources\HubstaffProjectMappings\HubstaffProjectMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHubstaffProjectMapping extends EditRecord
{
    protected static string $resource = HubstaffProjectMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
