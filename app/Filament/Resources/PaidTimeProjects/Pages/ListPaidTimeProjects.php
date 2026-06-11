<?php

namespace App\Filament\Resources\PaidTimeProjects\Pages;

use App\Filament\Resources\PaidTimeProjects\PaidTimeProjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaidTimeProjects extends ListRecords
{
    protected static string $resource = PaidTimeProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear'),
        ];
    }
}
