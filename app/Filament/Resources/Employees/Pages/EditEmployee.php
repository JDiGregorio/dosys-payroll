<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculationService;
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

        PayrollPeriod::query()
            ->where('status', '!=', 'cerrado')
            ->whereHas('dailyTimeReviews', fn ($query) => $query->where('employee_id', $this->record->id))
            ->each(function (PayrollPeriod $period): void {
                $service = app(PayrollCalculationService::class);
                $service->generateDailyReviews($period);
                $service->recalculateEmployeePayrollResult($period, $this->record);
            });
    }
}
