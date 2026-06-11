<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    private ?int $userAccountId = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->userAccountId = $data['user_account_id'] ?? null;
        unset($data['user_account_id']);

        return EmployeeResource::normalizeCompensation($data);
    }

    protected function afterSave(): void
    {
        EmployeeResource::syncUserAccount($this->record, $this->userAccountId);
    }
}
