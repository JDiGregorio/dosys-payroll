<?php

namespace App\Filament\Resources\EmployeeNameMappings\Pages;

use App\Filament\Resources\EmployeeNameMappings\EmployeeNameMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeNameMapping extends EditRecord
{
    protected static string $resource = EmployeeNameMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
