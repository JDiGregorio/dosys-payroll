<?php

namespace App\Filament\Resources\ContractTypes\Pages;

use App\Filament\Resources\ContractTypes\ContractTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContractTypes extends ListRecords
{
    protected static string $resource = ContractTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
