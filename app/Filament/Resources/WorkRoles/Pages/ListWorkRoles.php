<?php

namespace App\Filament\Resources\WorkRoles\Pages;

use App\Filament\Resources\WorkRoles\WorkRoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkRoles extends ListRecords
{
    protected static string $resource = WorkRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
