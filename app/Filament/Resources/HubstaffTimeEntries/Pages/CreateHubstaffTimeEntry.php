<?php

namespace App\Filament\Resources\HubstaffTimeEntries\Pages;

use App\Filament\Resources\HubstaffTimeEntries\HubstaffTimeEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHubstaffTimeEntry extends CreateRecord
{
    protected static string $resource = HubstaffTimeEntryResource::class;
}
