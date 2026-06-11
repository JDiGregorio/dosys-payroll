<?php

namespace App\Filament\Resources\WorkRoles\Pages;

use App\Filament\Resources\WorkRoles\WorkRoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkRole extends EditRecord
{
    protected static string $resource = WorkRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
