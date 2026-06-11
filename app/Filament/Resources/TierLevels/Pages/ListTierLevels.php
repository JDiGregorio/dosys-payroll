<?php

namespace App\Filament\Resources\TierLevels\Pages;

use App\Filament\Resources\TierLevels\TierLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTierLevels extends ListRecords
{
    protected static string $resource = TierLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
