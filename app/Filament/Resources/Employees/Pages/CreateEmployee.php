<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    private ?int $userAccountId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->userAccountId = $data['user_account_id'] ?? null;
        unset($data['user_account_id']);

        return EmployeeResource::normalizeCompensation($data);
    }

    protected function afterCreate(): void
    {
        EmployeeResource::syncUserAccount($this->record, $this->userAccountId);
    }
}
