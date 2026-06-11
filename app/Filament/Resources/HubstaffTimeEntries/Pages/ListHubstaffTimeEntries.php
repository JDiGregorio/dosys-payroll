<?php

namespace App\Filament\Resources\HubstaffTimeEntries\Pages;

use App\Filament\Resources\HubstaffTimeEntries\HubstaffTimeEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListHubstaffTimeEntries extends ListRecords
{
    protected static string $resource = HubstaffTimeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
