<?php

namespace App\Filament\Resources\PaidTimeProjects\Pages;

use App\Filament\Resources\PaidTimeProjects\PaidTimeProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaidTimeProject extends EditRecord
{
    protected static string $resource = PaidTimeProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
