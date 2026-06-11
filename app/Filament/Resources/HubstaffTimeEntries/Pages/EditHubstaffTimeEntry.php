<?php

namespace App\Filament\Resources\HubstaffTimeEntries\Pages;

use App\Filament\Resources\HubstaffTimeEntries\HubstaffTimeEntryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHubstaffTimeEntry extends EditRecord
{
    protected static string $resource = HubstaffTimeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
