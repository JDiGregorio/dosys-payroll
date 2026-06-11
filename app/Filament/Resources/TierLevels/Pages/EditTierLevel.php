<?php

namespace App\Filament\Resources\TierLevels\Pages;

use App\Filament\Resources\TierLevels\TierLevelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTierLevel extends EditRecord
{
    protected static string $resource = TierLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
